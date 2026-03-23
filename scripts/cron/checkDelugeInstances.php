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
echo date('Y-m-d H:i:s') . ': Checking Deluge instances' . "\n";
require_once __DIR__.'/../lib/userLifecycle.php';
if (is_file($pmssDelugePath = __DIR__.'/../lib/user/deluge.php')) { require_once $pmssDelugePath; }
pmssUserWatchdogRunEnabledUsers('delugeEnable', ['deluged', 'deluge-web'], 'deluge stopped due to suspension', function (string $thisUser): void {
    $delugedRunning = pmssUserWatchdogProcessRunning($thisUser, 'deluged');
    if (function_exists('pmssDelugeApplyUploadThrottle') && pmssDelugeApplyUploadThrottle($thisUser) && $delugedRunning) {
        pmssUserWatchdogTerminateProcesses($thisUser, ['deluged', 'deluge-web'], 9);
        pmssUserLog($thisUser, 'deluge restarted to apply upload throttle');
        $delugedRunning = false;
    }
    if (!$delugedRunning) {
        pmssUserWatchdogStartCommand($thisUser, 'deluged', "su {$thisUser} -c 'cd ~; deluged -l /home/{$thisUser}/.delugeLog -L info'", 'deluged start requested');
    }
 
    if (!pmssUserWatchdogProcessRunning($thisUser, 'deluge-web')) {
        pmssUserWatchdogStartCommand($thisUser, 'deluge-web', "su {$thisUser} -c 'cd ~; deluge-web -l /home/{$thisUser}/.delugeWebLog -L info'", 'deluge-web start requested');
    }
});
