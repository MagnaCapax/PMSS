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
    $qbittorrentRunning = pmssUserWatchdogProcessRunning($thisUser, 'qbittorrent-nox');
    if (function_exists('pmssQbittorrentApplyUploadThrottle') && pmssQbittorrentApplyUploadThrottle($thisUser) && $qbittorrentRunning) {
        passthru('killall -u '.escapeshellarg($thisUser).' -TERM qbittorrent-nox 2>/dev/null');
        pmssUserLog($thisUser, 'qbittorrent-nox restarted to apply upload throttle');
        $qbittorrentRunning = false;
    }
    !$qbittorrentRunning && pmssUserWatchdogEnsureRunning($thisUser, 'qbittorrent-nox', 'qBittorrent', "su {$thisUser} -c 'cd ~; nohup qbittorrent-nox -d >> /dev/null 2>&1 &'", 'qbittorrent-nox start requested');
});
