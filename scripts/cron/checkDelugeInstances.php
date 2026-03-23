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
$users = pmssListManagedUsers();
foreach($users AS $thisUser) {
    if (pmssUserWebRootUnavailable($thisUser)) {
            echo "User: {$thisUser} is suspended\n";
            // Kill only deluged and deluge-web — not all user processes (see GH#210).
            passthru("killall -9 -u ".escapeshellarg($thisUser)." deluged 2>/dev/null; killall -9 -u ".escapeshellarg($thisUser)." deluge-web 2>/dev/null");
            pmssUserLog($thisUser, 'deluge stopped due to suspension');
            continue;  //Suspended
    }

    if (!file_exists("/home/{$thisUser}/.delugeEnable")) continue;  // Deluge not enabled
    
    $instances = shell_exec("pgrep -u{$thisUser} deluged");
    if (function_exists('pmssDelugeApplyUploadThrottle') && pmssDelugeApplyUploadThrottle($thisUser) && !empty($instances)) {
        passthru("killall -9 -u ".escapeshellarg($thisUser)." deluged 2>/dev/null; killall -9 -u ".escapeshellarg($thisUser)." deluge-web 2>/dev/null");
        pmssUserLog($thisUser, 'deluge restarted to apply upload throttle');
        $instances = '';
    }
    if (empty($instances)) {
        echo "Start deluged for user: {$thisUser}\n";
        passthru("su {$thisUser} -c 'cd ~; deluged -l /home/{$thisUser}/.delugeLog -L info'");
        pmssUserLog($thisUser, 'deluged start requested');
    }
 
    if (empty(shell_exec("pgrep -u{$thisUser} deluge-web"))) {
        echo "Start deluge-web for user: {$thisUser}\n";
        passthru("su {$thisUser} -c 'cd ~; deluge-web -l /home/{$thisUser}/.delugeWebLog -L info'");
        pmssUserLog($thisUser, 'deluge-web start requested');
    }

}
