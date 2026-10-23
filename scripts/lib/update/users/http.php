<?php
/**
 * @domain user HTTP stack — per-user web stack
 *
 * Per-user HTTP maintenance for update-step2.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Track lighttpd refresh failures per user, including for standalone HTTP callers. */
function pmssUserLighttpdRefreshFailed(string $user, ?bool $failed = null): bool
{
    static $failures = [];
    if ($failed !== null) {
        $failures[$user] = $failed;
    }
    return $failures[$user] ?? false;
}

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
    [$user, $home] = pmssUserContextIdentity($ctx);
    $userEsc = $ctx['user_esc'];
    $userLog = static function (string $message) use ($user): void { pmssUserLog($user, $message); };

    $rc = runUserStep($user, 'Configuring lighttpd vhost', sprintf('/scripts/util/userConfigLighttpd.php %s', $userEsc));
    // Defer the completion marker without interrupting this user's other work.
    pmssUserLighttpdRefreshFailed($user, $rc !== 0);
    if ($rc !== 0) {
        $userLog('[WARN] lighttpd configuration refresh failed (rc='.$rc.'); continuing user maintenance');
    }

    // Refresh the PMSS-managed qBittorrent safety defaults on every pass.
    // This includes reverse-proxy compatibility keys such as HostHeaderValidation.
    $qbittorrentConfig = "{$home}/.config/qBittorrent/qBittorrent.conf";
    pmssQbittorrentWebUiAuthEnsure($user, $home);
    if (pmssQbittorrentApplyManagedConfig($user, $qbittorrentConfig)) {
        $userLog('Refreshed PMSS-managed qBittorrent settings');
    }

    $delugeConfig = "{$home}/.config/deluge/core.conf";
    if (pmssDelugeApplyManagedConfig($user, $delugeConfig)) {
        $userLog('Refreshed PMSS-managed Deluge settings');
    }

    $phpIniPath = "{$home}/.lighttpd/php.ini";
    if (file_exists($phpIniPath) && !pmssUserFilePathIsSafe($phpIniPath)) {
        $userLog('[WARN] Refusing unsafe php.ini path during HTTP maintenance');
    }

    if (!is_dir("{$home}/.tmp")) {
        pmssEnsureUserHomeDir($user, $home, '.tmp', 0755, $userLog);
    }

    $irssiDir = "{$home}/.irssi";
    if (!is_dir($irssiDir)) {
        // A conflicting or uncreatable path must not become a copy destination.
        if (pmssEnsureUserHomeDir($user, $home, '.irssi', 0755, $userLog)) {
            $skelConfigPath = pmssResolvePathFromEnv('PMSS_SKEL_DIR', '/etc/skel').'/.irssi/config';
            $copyCommand = sprintf('cp %s %s/', $skelConfigPath === '/etc/skel/.irssi/config' ? $skelConfigPath : escapeshellarg($skelConfigPath), escapeshellarg($irssiDir));
            runUserStep($user, 'Copying irssi skeleton config', pmssBuildUserShellCommand($user, $copyCommand));
        }
    }

    if (!is_dir("{$home}/www/recycle")) {
        // Note: parent directory mode should remain legacy defaults; only the recycle dir is 0771.
        pmssEnsureUserHomeDir($user, $home, 'www/recycle', 0771, $userLog, 0755);
    }
}
