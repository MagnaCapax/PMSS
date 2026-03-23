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
    $delugedRunning = pmssUserWatchdogRestartProcessesIf(
        $thisUser,
        pmssUserWatchdogProcessRunning($thisUser, 'deluged'),
        ['deluged', 'deluge-web'],
        static function () use ($thisUser): bool { return function_exists('pmssDelugeApplyUploadThrottle') && pmssDelugeApplyUploadThrottle($thisUser); },
        'deluge restarted to apply upload throttle'
    );
    pmssUserWatchdogEnsureServices($thisUser, [['processName' => 'deluged', 'command' => "su {$thisUser} -c 'cd ~; deluged -l /home/{$thisUser}/.delugeLog -L info'", 'userLogMessage' => 'deluged start requested'], ['processName' => 'deluge-web', 'command' => "su {$thisUser} -c 'cd ~; deluge-web -l /home/{$thisUser}/.delugeWebLog -L info'", 'userLogMessage' => 'deluge-web start requested']], ['deluged' => $delugedRunning]);
});
