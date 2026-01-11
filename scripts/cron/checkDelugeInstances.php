#!/usr/bin/env php
<?php
/**
 * checkDelugeInstances.php
 *
 * Cron helper that ensures each user with Deluge enabled has both the
 * daemon and web interface running. When either process is not found,
 * it is started under the user's account.
 */
echo date('Y-m-d H:i:s') . ': Checking Deluge instances' . "\n";

// Get & parse users list
$users = shell_exec('/scripts/listUsers.php');
$users = explode("\n", trim($users));

foreach($users AS $thisUser) {    // Loop users checking their instances
    #TODO(user-logs): log per-user start/kill actions to /var/log/pmss/user-<username>.log
    if (empty($thisUser)) continue;
    if (file_exists("/home/{$thisUser}/www-disabled") or 
        !file_exists("/home/{$thisUser}/www")) {
            echo "User: {$thisUser} is suspended\n";
            passthru("killall -9 -u {$thisUser}");
            #TODO(user-logs): record suspension cleanup in per-user log
            continue;  //Suspended
    }

    if (!file_exists("/home/{$thisUser}/.delugeEnable")) continue;  // Deluge not enabled
    
    $instances = shell_exec("pgrep -u{$thisUser} deluged");
    if (empty($instances)) startDeluged($thisUser);
    #TODO(user-logs): record deluged start in per-user log
 
    $instancesWeb = shell_exec("pgrep -u{$thisUser} deluge-web");
    if (empty($instancesWeb)) startDelugeWeb($thisUser);
    #TODO(user-logs): record deluge-web start in per-user log

}


function startDeluged($user) {    // start the user's Deluge daemon
    echo "Start deluged for user: {$user}\n";
    passthru("su {$user} -c 'cd ~; deluged -l /home/{$user}/.delugeLog -L info'");
}

function startDelugeWeb($user) {
    echo "Start deluge-web for user: {$user}\n";
    passthru("su {$user} -c 'cd ~; deluge-web -l /home/{$user}/.delugeWebLog -L info'");
}
