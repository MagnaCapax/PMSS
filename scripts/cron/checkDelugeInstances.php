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
if (is_file($pmssUserLogPath = __DIR__.'/../lib/user/log.php')) { require_once $pmssUserLogPath; }
if (is_file($pmssDelugePath = __DIR__.'/../lib/user/deluge.php')) { require_once $pmssDelugePath; }
$canUserLog = function_exists('pmssUserLog');

// Get & parse users list
$users = array_filter(explode("\n", trim((string) shell_exec('/scripts/listUsers.php'))));

foreach($users AS $thisUser) {    // Loop users checking their instances
    if (file_exists("/home/{$thisUser}/www-disabled") or
        !file_exists("/home/{$thisUser}/www")) {
            echo "User: {$thisUser} is suspended\n";
            // Kill only deluged and deluge-web — not all user processes (see GH#210).
            passthru("killall -9 -u ".escapeshellarg($thisUser)." deluged 2>/dev/null; killall -9 -u ".escapeshellarg($thisUser)." deluge-web 2>/dev/null");
            if ($canUserLog) { pmssUserLog($thisUser, 'deluge stopped due to suspension'); }
            continue;  //Suspended
    }

    if (!file_exists("/home/{$thisUser}/.delugeEnable")) continue;  // Deluge not enabled
    
    $instances = shell_exec("pgrep -u{$thisUser} deluged");
    $configChanged = function_exists('pmssDelugeApplyUploadThrottle')
        && pmssDelugeApplyUploadThrottle($thisUser);
    if ($configChanged && !empty($instances)) {
        passthru("killall -9 -u ".escapeshellarg($thisUser)." deluged 2>/dev/null; killall -9 -u ".escapeshellarg($thisUser)." deluge-web 2>/dev/null");
        if ($canUserLog) { pmssUserLog($thisUser, 'deluge restarted to apply upload throttle'); }
        $instances = '';
    }
    if (empty($instances)) {
        echo "Start deluged for user: {$thisUser}\n";
        passthru("su {$thisUser} -c 'cd ~; deluged -l /home/{$thisUser}/.delugeLog -L info'");
        if ($canUserLog) { pmssUserLog($thisUser, 'deluged start requested'); }
    }
 
    $instancesWeb = shell_exec("pgrep -u{$thisUser} deluge-web");
    if (empty($instancesWeb)) {
        echo "Start deluge-web for user: {$thisUser}\n";
        passthru("su {$thisUser} -c 'cd ~; deluge-web -l /home/{$thisUser}/.delugeWebLog -L info'");
        if ($canUserLog) { pmssUserLog($thisUser, 'deluge-web start requested'); }
    }

}
