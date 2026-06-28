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
pmssUserWatchdogRunService('qBittorrent', 'qbittorrentEnable', ['qbittorrent-nox'], 'qbittorrent-nox stopped due to suspension', [
    pmssUserWatchdogServiceSpec('qbittorrent-nox', static function (string $thisUser): string {
        return pmssUserWatchdogSuCommand($thisUser, 'cd ~; nohup qbittorrent-nox -d >> /dev/null 2>&1 &');
    }, 'qbittorrent-nox start requested', 'qBittorrent'),
], function (string $thisUser): array {
    $running = pmssUserWatchdogProcessRunning($thisUser, 'qbittorrent-nox');
    $home = rtrim(pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home'), '/').'/'.$thisUser;
    if (pmssQbittorrentPortEnsure($thisUser, $home)) {
        $running = pmssUserWatchdogProcessRunning($thisUser, 'qbittorrent-nox');
    }
    pmssUserWatchdogApplyManagedConfigWhenStopped($thisUser, $running, 'pmssQbittorrentApplyManagedConfig');
    $qbittorrentRunning = pmssUserWatchdogRestartProcessesIf($thisUser, $running, ['qbittorrent-nox'], static function () use ($thisUser): bool { return function_exists('pmssQbittorrentApplyUploadThrottle') && pmssQbittorrentApplyUploadThrottle($thisUser); }, 'qbittorrent-nox restarted to apply upload throttle', 15, static function () use ($thisUser): void { pmssUserWatchdogTerminateProcesses($thisUser, ['qbittorrent-nox'], 15); });
    return ['qbittorrent-nox' => $qbittorrentRunning];
}, __DIR__.'/../lib/user/qbittorrent.php');
