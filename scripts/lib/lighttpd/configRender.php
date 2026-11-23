<?php
/**
 * Per-user lighttpd and php.ini rendering helpers.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../user/identity.php';
require_once __DIR__.'/agentWebLogin.php';
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

/**
 * Whether the account opted in to buffered proxy responses (ADR 0338).
 *
 * The marker is account-writable and this runs as root: the signal is a regular
 * file owned by the account inside its own .lighttpd directory, never followed
 * through a link (a link would let the account probe which paths exist for
 * root), and the content is never read. Anything else, an invalid name
 * included, keeps the host default.
 */
function pmssLighttpdStreamResponseBuffered(string $user): bool
{
    if (!pmssUsernameIsValid($user)) {
        return false;
    }
    $home = pmssDirPathResolve(null, 'PMSS_HOME_DIR', '/home')."/{$user}";
    $paths = [$home, $home.'/.lighttpd', $home.'/.lighttpd/stream-response-body.buffered'];
    foreach ($paths as $path) {
        clearstatcache(true, $path);
    }
    $homeStat = @lstat($paths[0]);
    $dirStat = @lstat($paths[1]);
    $markerStat = @lstat($paths[2]);

    return is_array($homeStat) && is_array($dirStat) && is_array($markerStat)
        && ($dirStat['mode'] & 0170000) === 0040000 && ($markerStat['mode'] & 0170000) === 0100000
        && $dirStat['uid'] === $homeStat['uid'] && $markerStat['uid'] === $homeStat['uid'];
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
    if (file_exists(pmssDirPathResolve(null, 'PMSS_HOME_DIR', '/home')."/{$user}/.lighttpd/webdav.www-writable")) {
        return sprintf($policy, 'disable');
    }

    // is-readonly follows the request URL, so a COPY or MOVE from elsewhere would
    // still write into www/; refuse a Destination there (other than www/public).
    return sprintf($policy, 'enable').<<<LIGHTTPD

\$HTTP["url"] =~ "^/webdav-{$user}/www/public(\$|/)" {
    webdav.is-readonly = "disable"
}
# LOCK bypasses is-readonly and can create an empty resource at an unmapped URL.
\$HTTP["url"] =~ "^/webdav-{$user}/www(\$|/(?!public(\$|/)))" {
    \$HTTP["request-method"] == "LOCK" {
        url.access-deny = ( "" )
    }
}
\$REQUEST_HEADER["Destination"] =~ "/webdav-{$user}/www(\$|[?]|/(?!public(\$|[?/])))" {
    url.access-deny = ( "" )
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
        ["##username", "##serverPort", "##rclonePort", "##qbittorrentPort", "##PMSS_WEBDAV_WWW_POLICY##", "##PMSS_TRANSMISSION_PROXY##",
            "##PMSS_AGENT_WEB_LOGIN_REQUIRE##"],
        [$user, $serverPort, $rclonePort, $qbittorrentPort, pmssWebdavWwwPolicyBlock($user),
            $transmissionSelected ? pmssLighttpdTransmissionProxyBlock($user) : '',
            // Panel zone only; empty without the host marker (ADR 0151).
            pmssAgentWebLoginRequireSuffix($user)],
        (string) $template
    );
    $config = preg_replace(
        ['/("max-procs"\\s*=>\\s*)[0-9]+/', '/("max-load-per-proc"\\s*=>\\s*)[0-9]+/',
            '/("PHP_FCGI_CHILDREN"\\s*=>\\s*")[0-9]+(")/'],
        ['${1}'.(int) $resources['maxProcs'], '${1}'.(int) $resources['children'],
            '${1}'.(int) $resources['children'].'${2}'],
        $config,
        1
    );

    $config = pmssClampLighttpdBandwidthLimits(is_string($config) ? $config : (string) $template);
    if (pmssLighttpdStreamResponseBuffered($user)) {
        // Response side only: request streaming (uploads, WebDAV PUT) keeps its template mode.
        $config = preg_replace('/^(\s*server\.stream-response-body\s*=\s*)2\b/m', '${1}1', $config, 1);
    }
    $config = pmssLighttpdPanelSessionConfigApply($config, $user, $panelSessionLogin);
    $config .= pmssLighttpdPikkumiesUiBlock($user);
    $config .= pmssLighttpdPikkumiesHermesBlock($user);
    // Route before installation, so account creation and Configure can verify host readiness.
    $reservation = '/etc/seedbox/runtime/ports/media-stack-hermes-'.$user;
    $port = function_exists('pmssReadRegularFileNetworkPort') ? pmssReadRegularFileNetworkPort($reservation) : null;
    if ($port !== null && $port >= 1024) $config .= pmssLighttpdHermesUiBlock($user, $port);
    return $config;
}

function pmssLighttpdApplyPhpIniContent(string $content, string $user, int $memoryLimitMiB): string
{
    // Keep upload temp files quota-bound while replacing managed php.ini lines consistently.
    // No PMSS page reads upload progress, so the managed php.ini keeps it off.
    foreach ([
        '/^memory_limit\s*=.*$/m' => 'memory_limit = '.$memoryLimitMiB.'M',
        '/^\s*;?\s*upload_tmp_dir\s*=.*$/m' => 'upload_tmp_dir = /home/'.$user.'/.lighttpd/upload',
        '/^\s*;?\s*error_log\s*=.*$/m' => 'error_log = /home/'.$user.'/.lighttpd/error.log',
        '/^\s*;?\s*session\.upload_progress\.enabled\s*=.*$/m' => 'session.upload_progress.enabled = Off',
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
