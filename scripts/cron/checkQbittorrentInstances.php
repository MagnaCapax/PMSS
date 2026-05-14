#!/usr/bin/env php
<?php
/**
 * Cron task: check Qbittorrent Instances.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../lib/userLifecycle.php';
pmssUserWatchdogRunService('qBittorrent', 'qbittorrentEnable', ['qbittorrent-nox'], 'qbittorrent-nox stopped due to suspension', [
    ['processName' => 'qbittorrent-nox', 'serviceLabel' => 'qBittorrent', 'command' => static function (string $thisUser): string {
        $innerCommand = 'cd ~; nohup qbittorrent-nox -d >> /dev/null 2>&1 &';
        return 'su '.escapeshellarg($thisUser).' -c '.escapeshellarg($innerCommand);
    }, 'userLogMessage' => 'qbittorrent-nox start requested'],
], function (string $thisUser): array {
    $qbittorrentRunning = pmssUserWatchdogRestartProcessesIf($thisUser, pmssUserWatchdogProcessRunning($thisUser, 'qbittorrent-nox'), ['qbittorrent-nox'], static function () use ($thisUser): bool { return function_exists('pmssQbittorrentApplyUploadThrottle') && pmssQbittorrentApplyUploadThrottle($thisUser); }, 'qbittorrent-nox restarted to apply upload throttle', 15, static function () use ($thisUser): void { passthru('killall -u '.escapeshellarg($thisUser).' -TERM qbittorrent-nox 2>/dev/null'); });
    return ['qbittorrent-nox' => $qbittorrentRunning];
}, __DIR__.'/../lib/user/qbittorrent.php');
