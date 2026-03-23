#!/usr/bin/env php
<?php
/**
 * Cron task: check Qbittorrent Instances.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
echo date('Y-m-d H:i:s') . ': Checking qBittorrent instances' . "\n";
require_once __DIR__.'/../lib/userLifecycle.php';
if (is_file($pmssQbittorrentPath = __DIR__.'/../lib/user/qbittorrent.php')) { require_once $pmssQbittorrentPath; }
pmssUserWatchdogRunEnabledUsers('qbittorrentEnable', ['qbittorrent-nox'], 'qbittorrent-nox stopped due to suspension', function (string $thisUser): void {
    $qbittorrentRunning = pmssUserWatchdogRestartProcessesIf(
        $thisUser,
        pmssUserWatchdogProcessRunning($thisUser, 'qbittorrent-nox'),
        ['qbittorrent-nox'],
        static function () use ($thisUser): bool { return function_exists('pmssQbittorrentApplyUploadThrottle') && pmssQbittorrentApplyUploadThrottle($thisUser); },
        'qbittorrent-nox restarted to apply upload throttle',
        15,
        static function () use ($thisUser): void { passthru('killall -u '.escapeshellarg($thisUser).' -TERM qbittorrent-nox 2>/dev/null'); }
    );
    pmssUserWatchdogEnsureServices($thisUser, [['processName' => 'qbittorrent-nox', 'serviceLabel' => 'qBittorrent', 'command' => "su {$thisUser} -c 'cd ~; nohup qbittorrent-nox -d >> /dev/null 2>&1 &'", 'userLogMessage' => 'qbittorrent-nox start requested']], ['qbittorrent-nox' => $qbittorrentRunning]);
});
