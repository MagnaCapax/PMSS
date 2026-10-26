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
require_once __DIR__.'/../lib/user/qbittorrentWatchdog.php';
require_once __DIR__.'/../lib/torrentClients/identity.php';
require_once __DIR__.'/../lib/lighttpd/dockerClientProxy.php';

$pmssCheckQbittorrentLock = pmssCronLockAcquire('checkQbittorrentInstances', 'pmssCronLockSkipLog', 'pmssUserWatchdogLockAcquire');

pmssUserWatchdogRunService('qBittorrent', 'qbittorrentEnable', ['qbittorrent-nox'], 'qbittorrent-nox stopped: suspended or moved', [
    pmssUserWatchdogServiceSpec('qbittorrent-nox', static function (string $thisUser): string {
        return pmssBuildUserServiceShellCommand($thisUser, 'mkdir -m 700 -p ~/.config/pmss/torrentClients || exit 1; exec 9>>~/.config/pmss/torrentClients/qbittorrent.lock; flock -n 9 || exit 0; cd ~; [ ! -e ~/.config/pmss/torrentClients/qbittorrent.refused ] || exit 1; if [ -f ~/www/qbittorrentStart.php ]; then php ~/www/torrentClientsResolve.php qbittorrent --launch --held --system 9>&-; else qbittorrent-nox -d 9>&-; fi; pmssQbtStartRc=$?; exit "$pmssQbtStartRc"');
    }, 'qbittorrent-nox start requested', 'qBittorrent'),
], function (string $thisUser): array {
    // A running, labelled Docker qBittorrent is this account's client; never start a native one beside it.
    if (pmssLighttpdDockerClientPort($thisUser, 'qbittorrent') !== null) return ['qbittorrent-nox' => true];
    $home = rtrim(pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home'), '/').'/'.$thisUser;
    return ['qbittorrent-nox' => pmssQbittorrentWatchdogRunning($thisUser, $home)];
}, __DIR__.'/../lib/user/qbittorrent.php', '/home', '/scripts/listUsers.php', true);
