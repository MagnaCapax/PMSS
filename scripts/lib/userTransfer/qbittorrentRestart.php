<?php
/**
 * Reload migrated native qBittorrent sessions through the existing watchdog.
 * @license GPL-3.0-only
 */
require_once dirname(__DIR__).'/torrentClients/identity.php';

/** Request a graceful stop only for an enabled account's native client. */
function pmssUserTransferRequestQbittorrentRestart(string $home, string $localUser, array $ops = []): void
{
    $marker = rtrim($home, '/').'/.qbittorrentEnable';
    if (is_link($marker) || !is_file($marker)) {
        return;
    }
    if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
        logMessage('[SKIP] Requesting native qBittorrent restart (dry run)');
        return;
    }

    // The shared predicate rechecks UID, executable and mount namespace before
    // each signal: Docker instances and other accounts must remain untouched.
    $stopped = pmssTorrentClientNativeStop($localUser, 'qbittorrent', 15, $ops);
    logMessage($stopped > 0
        ? '[OK] Native qBittorrent stop requested; watchdog will reload migrated sessions'
        : '[INFO] No native qBittorrent process stopped');
}
