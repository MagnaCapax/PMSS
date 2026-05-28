<?php
/**
 * Per-user lighttpd and php.ini rendering helpers.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../userLifecycle.php';
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
    array $resources
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

    return pmssClampLighttpdBandwidthLimits(is_string($config) ? $config : (string) $template);
}

function pmssLighttpdApplyPhpIniContent(string $content, string $user, int $memoryLimitMiB): string
{
    $memoryLine = 'memory_limit = '.$memoryLimitMiB.'M';
    $updated = preg_match('/^memory_limit\s*=.*$/m', $content)
        ? preg_replace('/^memory_limit\s*=.*$/m', $memoryLine, $content, 1)
        : rtrim($content, "\n")."\n".$memoryLine."\n";
    $content = is_string($updated) ? $updated : $content;

    // Keep upload temp files inside the user's quota-bound tree, not shared /tmp.
    $uploadTmpDirLine = 'upload_tmp_dir = /home/'.$user.'/.lighttpd/upload';
    $updated = preg_match('/^\s*;?\s*upload_tmp_dir\s*=.*$/m', $content)
        ? preg_replace('/^\s*;?\s*upload_tmp_dir\s*=.*$/m', $uploadTmpDirLine, $content, 1)
        : rtrim($content, "\n")."\n".$uploadTmpDirLine."\n";

    return is_string($updated) ? $updated : $content;
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
    $phpIniContent = !is_link($phpIniPath) && is_file($phpIniPath) ? @file_get_contents($phpIniPath) : false;
    if (is_string($phpIniContent) && !pmssAtomicWriteFile($phpIniPath, pmssLighttpdApplyPhpIniContent($phpIniContent, $user, $memoryLimitMiB))) {
        $failureReason = 'update';
        return false;
    }

    @chmod($phpIniPath, 0751);
    if (function_exists('posix_geteuid') && @posix_geteuid() === 0) {
        @chown($phpIniPath, $user);
        @chgrp($phpIniPath, $user);
    }

    return true;
}
