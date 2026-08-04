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

$pmssCheckDelugeLock = pmssLockFileAcquire(pmssRuntimeLockPath('pmss-checkDelugeInstances.lock'), true);
if ($pmssCheckDelugeLock === false) {
    echo date('Y-m-d H:i:s').': checkDelugeInstances already running; skipping' . "\n";
    exit(0);
}

pmssUserWatchdogRunService('Deluge', 'delugeEnable', ['deluged', 'deluge-web'], 'deluge stopped due to suspension', [
    pmssUserWatchdogServiceSpec('deluged', static function (string $thisUser): string {
        return pmssUserWatchdogSuCommand($thisUser, "cd ~; deluged -l /home/{$thisUser}/.delugeLog -L info");
    }, 'deluged start requested'),
    pmssUserWatchdogServiceSpec('deluge-web', static function (string $thisUser): string {
        return pmssUserWatchdogSuCommand($thisUser, "cd ~; deluge-web -l /home/{$thisUser}/.delugeWebLog -L info");
    }, 'deluge-web start requested'),
], function (string $thisUser): array {
    $running = pmssUserWatchdogProcessRunning($thisUser, 'deluged');
    pmssUserWatchdogApplyManagedConfigWhenStopped($thisUser, $running, 'pmssDelugeApplyManagedConfig');
    $delugedRunning = pmssUserWatchdogRestartProcessesIf($thisUser, $running, ['deluged', 'deluge-web'], static function () use ($thisUser): bool { return function_exists('pmssDelugeApplyUploadThrottle') && pmssDelugeApplyUploadThrottle($thisUser); }, 'deluge restarted to apply upload throttle');
    return ['deluged' => $delugedRunning];
}, __DIR__.'/../lib/user/deluge.php');
