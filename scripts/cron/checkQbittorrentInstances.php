#!/usr/bin/env php
<?php
/**
 * Cron task: check Qbittorrent Instances.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../lib/user/watchdog.php';
require_once __DIR__.'/../lib/user/torrentPort.php';
require_once __DIR__.'/../lib/user/qbittorrentWebUiAuth.php';
require_once __DIR__.'/../lib/torrentClients/identity.php';

$pmssCheckQbittorrentLock = pmssCronLockAcquire('checkQbittorrentInstances', 'pmssCronLockSkipLog', 'pmssUserWatchdogLockAcquire');

pmssUserWatchdogRunService('qBittorrent', 'qbittorrentEnable', ['qbittorrent-nox'], 'qbittorrent-nox stopped due to suspension', [
    pmssUserWatchdogServiceSpec('qbittorrent-nox', static function (string $thisUser): string {
        return pmssBuildUserServiceShellCommand($thisUser, '[ ! -L ~/bin/qbittorrent/default ] || mkdir -m 700 -p ~/.config/pmss/torrentClients || exit 0; [ ! -d ~/.config/pmss/torrentClients ] || { exec 9>>~/.config/pmss/torrentClients/qbittorrent.lock; flock -n 9 || exit 0; }; [ -L ~/bin/qbittorrent/default ] && [ -f /etc/seedbox/config/torrentClients.enabled ] && (cd ~; php ~/www/torrentClientsResolve.php qbittorrent --launch --held >/dev/null 2>&1; :) 9>&- || (cd ~; nohup qbittorrent-nox -d >> /dev/null 2>&1 &) 9>&-');
    }, 'qbittorrent-nox start requested', 'qBittorrent'),
], function (string $thisUser): array {
    $running = pmssTorrentClientLauncherLivenessPids($thisUser, 'qbittorrent') !== [];
    $home = rtrim(pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home'), '/').'/'.$thisUser;
    if (pmssQbittorrentPortEnsure($thisUser, $home)) {
        $running = pmssTorrentClientLauncherLivenessPids($thisUser, 'qbittorrent') !== [];
    }
    if (pmssQbittorrentWebUiAuthEnsure($thisUser, $home)) {
        $running = false;
    }
    pmssUserWatchdogApplyManagedConfigWhenStopped($thisUser, $running, 'pmssQbittorrentApplyManagedConfig');
    $qbittorrentRunning = pmssUserWatchdogRestartProcessesIf($thisUser, $running, ['qbittorrent-nox'], static function () use ($thisUser): bool { return function_exists('pmssQbittorrentApplyUploadThrottle') && pmssQbittorrentApplyUploadThrottle($thisUser); }, 'qbittorrent-nox restarted to apply upload throttle', 15, static function () use ($thisUser): void { pmssTorrentClientNativeStop($thisUser, 'qbittorrent', 15); });
    return ['qbittorrent-nox' => $qbittorrentRunning];
}, __DIR__.'/../lib/user/qbittorrent.php');
