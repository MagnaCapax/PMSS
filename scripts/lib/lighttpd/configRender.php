<?php
/**
 * Per-user lighttpd and php.ini rendering helpers.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../user/identity.php';
require_once __DIR__.'/panelSessionLogin.php';
require_once __DIR__.'/userFileWrite.php';
require_once __DIR__.'/transmissionProxy.php';
require_once __DIR__.'/pikkumiesUi.php';

function pmssClampLighttpdBandwidthLimits(string $config): string
{
    $clamped = preg_replace_callback(
        '/^(\\s*(?:connection|server)\\.kbytes-per-second\\s*=\\s*)(\\d+)(\\s*(?:#.*)?)$/m',
        static function (array $matches): string {
            return $matches[1].(((int) $matches[2]) > 65535 ? 0 : (int) $matches[2]).$matches[3];
        },
        $config
    );

    return is_string($clamped) ? $clamped : $config;
}

function pmssWebdavWwwPolicyBlock(string $user): string
{
    if (!pmssUsernameIsValid($user)) {
        error_log('pmssWebdavWwwPolicyBlock: rejected invalid username: '.substr($user, 0, 20));
        return '# WebDAV www policy skipped: invalid username';
    }

    $policy = <<<LIGHTTPD
\$HTTP["url"] =~ "^/webdav-{$user}/www(\$|/)" {
    webdav.is-readonly = "%s"
}
LIGHTTPD;
    if (file_exists("/home/{$user}/.lighttpd/webdav.www-writable")) {
        return sprintf($policy, 'disable');
    }

    return sprintf($policy, 'enable').<<<LIGHTTPD

\$HTTP["url"] =~ "^/webdav-{$user}/www/public(\$|/)" {
    webdav.is-readonly = "disable"
}
LIGHTTPD;
}

function pmssStripLighttpdWebdavConfig(string $template): string
{
    $template = preg_replace('/^\\s*#\\s*PMSS_WEBDAV_BEGIN\\s*$.*^\\s*#\\s*PMSS_WEBDAV_END\\s*$\\s*/ms', '', $template);
    $template = preg_replace('/^(\\s*)\"mod_webdav\",\\s*$/m', '${1}#"mod_webdav",', (string) $template, 1);

    return str_replace('##PMSS_WEBDAV_WWW_POLICY##', '', (string) $template);
}

function pmssLighttpdRenderUserConfig(
    $template,
    string $user,
    int $serverPort,
    int $rclonePort,
    int $qbittorrentPort,
    array $resources,
    array $panelSessionLogin = [],
    ?bool $transmissionSelected = null
): string {
    if ($transmissionSelected === null) {
        $transmissionSelected = !(defined('PMSS_TEST_MODE') && PMSS_TEST_MODE)
            && pmssLighttpdTransmissionSelected($user, '/home/'.$user);
    }
    $config = str_replace(
        ["##username", "##serverPort", "##rclonePort", "##qbittorrentPort", "##PMSS_WEBDAV_WWW_POLICY##", "##PMSS_TRANSMISSION_PROXY##"],
        [$user, $serverPort, $rclonePort, $qbittorrentPort, pmssWebdavWwwPolicyBlock($user),
            $transmissionSelected ? pmssLighttpdTransmissionProxyBlock($user) : ''],
        (string) $template
    );
    $config = preg_replace(
        ['/("max-procs"\\s*=>\\s*)[0-9]+/', '/("PHP_FCGI_CHILDREN"\\s*=>\\s*")[0-9]+(")/'],
        ['${1}'.(int) $resources['maxProcs'], '${1}'.(int) $resources['children'].'${2}'],
        $config,
        1
    );

    $config = pmssClampLighttpdBandwidthLimits(is_string($config) ? $config : (string) $template);
    $config = pmssLighttpdPanelSessionConfigApply($config, $user, $panelSessionLogin);
    if (is_file('/etc/seedbox/config/pikkumiesEternals.enabled')
        && is_file('/opt/pikkumies-agentboard/agentboard')) {
        $config .= pmssLighttpdPikkumiesUiBlock($user);
    }
    if (is_file('/etc/seedbox/config/pikkumiesEternals.enabled')) {
        $bPort = function_exists('pmssReadRegularFileNetworkPort')
            ? pmssReadRegularFileNetworkPort('/etc/seedbox/runtime/ports/media-stack-pikkumies-hermes-'.$user) : null;
        if ($bPort !== null) $config .= pmssLighttpdPikkumiesHermesBlock($user, $bPort);
    }
    // Root reads only the root-owned reservation; the account status merely opts in.
    $status = '/home/'.$user.'/.hermes/pmss-install.status';
    $reservation = '/etc/seedbox/runtime/ports/media-stack-hermes-'.$user;
    $port = function_exists('pmssReadRegularFileNetworkPort') ? pmssReadRegularFileNetworkPort($reservation) : null;
    if ($port !== null && is_file($status) && !is_link($status) && @filesize($status) <= 32
        && trim((string) @file_get_contents($status)) === 'ready') {
        $config .= pmssLighttpdHermesUiBlock($user, $port)
            .pmssLighttpdHermesUiBlock($user, $port, 'vanilla-agentboard');
    }
    return $config;
}

function pmssLighttpdApplyPhpIniContent(string $content, string $user, int $memoryLimitMiB): string
{
    // Keep upload temp files quota-bound while replacing managed php.ini lines consistently.
    foreach ([
        '/^memory_limit\s*=.*$/m' => 'memory_limit = '.$memoryLimitMiB.'M',
        '/^\s*;?\s*upload_tmp_dir\s*=.*$/m' => 'upload_tmp_dir = /home/'.$user.'/.lighttpd/upload',
        '/^\s*;?\s*error_log\s*=.*$/m' => 'error_log = /home/'.$user.'/.lighttpd/error.log',
    ] as $pattern => $line) {
        $updated = preg_match($pattern, $content)
            ? preg_replace($pattern, $line, $content, 1)
            : rtrim($content, "\n")."\n".$line."\n";
        $content = is_string($updated) ? $updated : $content;
    }

    return $content;
}

/**
 * Write account-owned php.ini content while accepting a legacy root-owned entry.
 * Only an existing regular entry with one link may use the managed writer; new
 * and account-owned entries are replaced under the account identity.
 */
function pmssLighttpdPhpIniWrite(string $path, string $user, string $content): bool
{
    $home = dirname(dirname($path));
    if (pmssReplaceAccountFile($user, $home, $path, $content, 0640)) {
        return true;
    }
    $entry = @stat($path);
    return pmssUserFilePathIsSafe($path)
        && basename($path) === 'php.ini' && basename(dirname($path)) === '.lighttpd'
        && pmssPathWithinResolvedRoot($path, $home)
        && is_file($path) && is_array($entry) && $entry['uid'] === 0 && $entry['nlink'] === 1
        && pmssWriteUserFile($path, $content, $user, 0640);
}

function pmssLighttpdSyncPhpIni(string $phpIniPath, string $user, int $memoryLimitMiB, &$failureReason = null): bool
{
    $failureReason = '';
    if (!pmssLockFilePathIsSafe($phpIniPath)) {
        $failureReason = 'unsafe';
        return false;
    }
    if (!file_exists($phpIniPath)) {
        $skelPhpIni = @file_get_contents('/etc/skel/.lighttpd/php.ini');
        if (!is_string($skelPhpIni) || !pmssLighttpdPhpIniWrite($phpIniPath, $user, $skelPhpIni)) {
            $failureReason = 'seed';
            return false;
        }
    }
    $phpIniContent = pmssReadRegularFileContents($phpIniPath);
    if (is_string($phpIniContent) && !pmssLighttpdPhpIniWrite($phpIniPath, $user, pmssLighttpdApplyPhpIniContent($phpIniContent, $user, $memoryLimitMiB))) {
        $failureReason = 'update';
        return false;
    }

    if ($phpIniContent === null) {
        pmssUserFileApplyMetadata($phpIniPath, $user, 0640);
    }

    return true;
}
