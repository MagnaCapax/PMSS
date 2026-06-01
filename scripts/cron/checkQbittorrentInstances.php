#!/usr/bin/env php
<?php
/**
 * Cron task: check Qbittorrent Instances.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../lib/user/watchdog.php';
pmssUserWatchdogRunService('qBittorrent', 'qbittorrentEnable', ['qbittorrent-nox'], 'qbittorrent-nox stopped due to suspension', [
    pmssUserWatchdogServiceSpec('qbittorrent-nox', static function (string $thisUser): string {
        return pmssUserWatchdogSuCommand($thisUser, 'cd ~; nohup qbittorrent-nox -d >> /dev/null 2>&1 &');
    }, 'qbittorrent-nox start requested', 'qBittorrent'),
], function (string $thisUser): array {
    $running = pmssUserWatchdogProcessRunning($thisUser, 'qbittorrent-nox');
    // Clobber-safe managed-config enforcement: apply the PMSS-managed config ONLY while
    // qBittorrent is stopped, so the imminent same-pass start reads it. Applying while the
    // daemon runs is futile and unsafe -- qBittorrent rewrites its whole config from memory
    // on exit and would clobber the edit -- so managed defaults (incl. Session\Preallocation)
    // land on the next start rather than via a risky live edit + forced restart.
    if (!$running && function_exists('pmssQbittorrentApplyManagedConfig')) {
        pmssQbittorrentApplyManagedConfig($thisUser);
    }
    $qbittorrentRunning = pmssUserWatchdogRestartProcessesIf($thisUser, $running, ['qbittorrent-nox'], static function () use ($thisUser): bool { return function_exists('pmssQbittorrentApplyUploadThrottle') && pmssQbittorrentApplyUploadThrottle($thisUser); }, 'qbittorrent-nox restarted to apply upload throttle', 15, static function () use ($thisUser): void { passthru('killall -u '.escapeshellarg($thisUser).' -TERM qbittorrent-nox 2>/dev/null'); });
    return ['qbittorrent-nox' => $qbittorrentRunning];
}, __DIR__.'/../lib/user/qbittorrent.php');
