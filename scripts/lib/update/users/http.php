<?php
/**
 * @domain user HTTP stack — per-user web stack
 *
 * Per-user HTTP maintenance for update-step2.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Configure per-user HTTP stack pieces (lighttpd vhost, ruTorrent temp paths).
 *
 * Suspension handling: callers must only invoke this when the user context is
 * non-null (pmssBuildUserContext filters out suspended users via `www-disabled`).
 *
 * @param array $ctx Per-user context from pmssBuildUserContext().
 */
function pmssUserConfigureHttp(array $ctx): void
{
    $user    = $ctx['user'];
    $home    = $ctx['home'];
    $userEsc = $ctx['user_esc'];
    $userLog = function_exists('pmssUserLog') ? static function (string $message) use ($user): void { pmssUserLog($user, $message); } : null;

    runUserStep($user, 'Configuring lighttpd vhost', sprintf('/scripts/util/userConfigLighttpd.php %s', $userEsc));

    // Keep qBittorrent WebUI reverse-proxy compatibility settings disabled.
    $qbittorrentConfig = "{$home}/.config/qBittorrent/qBittorrent.conf";
    if (is_file($qbittorrentConfig) && is_string($config = file_get_contents($qbittorrentConfig))) {
        $originalConfig = $config;
        foreach (['HostHeaderValidation', 'CSRFProtection', 'ClickjackingProtection'] as $setting) {
            $updated = preg_replace('/^WebUI\\\\'.preg_quote($setting, '/').'=.*$/m', 'WebUI\\'.$setting.'=false', $config, 1, $count);
            if ($count < 1 || $updated === null || $updated === $config) {
                continue;
            }

            $config = $updated;
            if ($userLog) {
                $userLog('Updated qBittorrent WebUI '.$setting.' to false');
            }
        }

        if ($config !== $originalConfig) {
            file_put_contents($qbittorrentConfig, $config);
        }
    }

    $phpIniPath = "{$home}/.lighttpd/php.ini";
    if (($phpIni = @parse_ini_file($phpIniPath)) !== false && !isset($phpIni['error_log'])) {
        $phpIni['error_log'] = "{$home}/.lighttpd/error.log";
        $newContent = '';
        foreach ($phpIni as $key => $value) {
            $newContent .= sprintf('%s = "%s"\n', $key, $value);
        }
        file_put_contents($phpIniPath, $newContent);
        echo "Updated php.ini for user {$user}\n";
    }

    if (!is_dir("{$home}/.tmp")) {
        pmssEnsureUserHomeDir($user, $home, '.tmp', 0755, $userLog);
    }

    $irssiDir = "{$home}/.irssi";
    if (!is_dir($irssiDir)) {
        pmssEnsureUserHomeDir($user, $home, '.irssi', 0755, $userLog);
        $skelConfigPath = pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel').'/.irssi/config';
        runUserStep($user, 'Copying irssi skeleton config', sprintf('cp %s %s/', $skelConfigPath === '/etc/skel/.irssi/config' ? $skelConfigPath : escapeshellarg($skelConfigPath), escapeshellarg($irssiDir)));
        runUserStep($user, 'Adjusting irssi configuration ownership', sprintf('chown -R %1$s:%1$s %2$s', $userEsc, escapeshellarg($irssiDir)));
    }

    if (!is_dir("{$home}/www/recycle")) {
        // Note: parent directory mode should remain legacy defaults; only the recycle dir is 0771.
        pmssEnsureUserHomeDir($user, $home, 'www/recycle', 0771, $userLog, 0755);
    }
}
