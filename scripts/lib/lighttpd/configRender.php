<?php
/**
 * Per-user lighttpd and php.ini rendering helpers.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../user/identity.php';
require_once __DIR__.'/panelSessionLogin.php';
require_once __DIR__.'/userFileWrite.php';

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
    array $panelSessionLogin = []
): string {
    $config = str_replace(
        ["##username", "##serverPort", "##rclonePort", "##qbittorrentPort", "##PMSS_WEBDAV_WWW_POLICY##"],
        [$user, $serverPort, $rclonePort, $qbittorrentPort, pmssWebdavWwwPolicyBlock($user)],
        (string) $template
    );
    $config = preg_replace(
        ['/("max-procs"\\s*=>\\s*)[0-9]+/', '/("PHP_FCGI_CHILDREN"\\s*=>\\s*")[0-9]+(")/'],
        ['${1}'.(int) $resources['maxProcs'], '${1}'.(int) $resources['children'].'${2}'],
        $config,
        1
    );

    $config = pmssClampLighttpdBandwidthLimits(is_string($config) ? $config : (string) $template);
    return pmssLighttpdPanelSessionConfigApply($config, $user, $panelSessionLogin);
}

function pmssLighttpdApplyPhpIniContent(string $content, string $user, int $memoryLimitMiB): string
{
    // Keep upload temp files quota-bound while replacing managed php.ini lines consistently.
    foreach ([
        '/^memory_limit\s*=.*$/m' => 'memory_limit = '.$memoryLimitMiB.'M',
        '/^\s*;?\s*upload_tmp_dir\s*=.*$/m' => 'upload_tmp_dir = /home/'.$user.'/.lighttpd/upload',
    ] as $pattern => $line) {
        $updated = preg_match($pattern, $content)
            ? preg_replace($pattern, $line, $content, 1)
            : rtrim($content, "\n")."\n".$line."\n";
        $content = is_string($updated) ? $updated : $content;
    }

    return $content;
}

function pmssLighttpdSyncPhpIni(string $phpIniPath, string $user, int $memoryLimitMiB, &$failureReason = null): bool
{
    $failureReason = '';
    if (is_link($phpIniPath) || (file_exists($phpIniPath) && !is_file($phpIniPath))) {
        $failureReason = 'unsafe';
        return false;
    }
    if (!file_exists($phpIniPath)) {
        $skelPhpIni = @file_get_contents('/etc/skel/.lighttpd/php.ini');
        if (!is_string($skelPhpIni) || !pmssWriteUserFile($phpIniPath, $skelPhpIni, $user, 0751)) {
            $failureReason = 'seed';
            return false;
        }
    }
    $phpIniContent = pmssReadRegularFileContents($phpIniPath);
    if (is_string($phpIniContent) && !pmssAtomicWriteFile($phpIniPath, pmssLighttpdApplyPhpIniContent($phpIniContent, $user, $memoryLimitMiB))) {
        $failureReason = 'update';
        return false;
    }

    pmssUserFileApplyMetadata($phpIniPath, $user, 0751);

    return true;
}
