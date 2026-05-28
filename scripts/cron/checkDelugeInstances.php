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
pmssUserWatchdogRunService('Deluge', 'delugeEnable', ['deluged', 'deluge-web'], 'deluge stopped due to suspension', [
    ['processName' => 'deluged', 'command' => static function (string $thisUser): string {
        return pmssUserWatchdogSuCommand($thisUser, "cd ~; deluged -l /home/{$thisUser}/.delugeLog -L info");
    }, 'userLogMessage' => 'deluged start requested'],
    ['processName' => 'deluge-web', 'command' => static function (string $thisUser): string {
        return pmssUserWatchdogSuCommand($thisUser, "cd ~; deluge-web -l /home/{$thisUser}/.delugeWebLog -L info");
    }, 'userLogMessage' => 'deluge-web start requested'],
], function (string $thisUser): array {
    $delugedRunning = pmssUserWatchdogRestartProcessesIf($thisUser, pmssUserWatchdogProcessRunning($thisUser, 'deluged'), ['deluged', 'deluge-web'], static function () use ($thisUser): bool { return function_exists('pmssDelugeApplyUploadThrottle') && pmssDelugeApplyUploadThrottle($thisUser); }, 'deluge restarted to apply upload throttle');
    return ['deluged' => $delugedRunning];
}, __DIR__.'/../lib/user/deluge.php');
