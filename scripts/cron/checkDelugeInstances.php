#!/usr/bin/env php
<?php
/**
 * checkDelugeInstances.php
 *
 * Cron helper that ensures each user with Deluge enabled has both the
 * daemon and web interface running. When either process is not found,
 * it is started under the user's account.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../lib/user/watchdog.php';
require_once __DIR__.'/../lib/user/delugeManagedConfig.php';
pmssUserWatchdogRunService('Deluge', 'delugeEnable', ['deluged', 'deluge-web'], 'deluge stopped due to suspension', [
    pmssUserWatchdogServiceSpec('deluged', static function (string $thisUser): string {
        return pmssUserWatchdogSuCommand($thisUser, "cd ~; deluged -l /home/{$thisUser}/.delugeLog -L info");
    }, 'deluged start requested'),
    pmssUserWatchdogServiceSpec('deluge-web', static function (string $thisUser): string {
        return pmssUserWatchdogSuCommand($thisUser, "cd ~; deluge-web -l /home/{$thisUser}/.delugeWebLog -L info");
    }, 'deluge-web start requested'),
], function (string $thisUser): array {
    $running = pmssUserWatchdogProcessRunning($thisUser, 'deluged');
    // Clobber-safe managed-config enforcement (mirrors checkQbittorrentInstances): apply the
    // PMSS-managed Deluge config ONLY while deluged is stopped, so the imminent same-pass start
    // reads it. deluged rewrites core.conf from memory on exit, so a live edit would be clobbered;
    // managed defaults (incl. pre_allocate_storage) thus land on the next start.
    if (!$running && function_exists('pmssDelugeApplyManagedConfig')) {
        pmssDelugeApplyManagedConfig($thisUser);
    }
    $delugedRunning = pmssUserWatchdogRestartProcessesIf($thisUser, $running, ['deluged', 'deluge-web'], static function () use ($thisUser): bool { return function_exists('pmssDelugeApplyUploadThrottle') && pmssDelugeApplyUploadThrottle($thisUser); }, 'deluge restarted to apply upload throttle');
    return ['deluged' => $delugedRunning];
}, __DIR__.'/../lib/user/deluge.php');
