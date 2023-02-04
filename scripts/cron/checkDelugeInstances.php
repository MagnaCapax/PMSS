#!/usr/bin/php
<?php
echo date('Y-m-d H:i:s') . ': Checking Deluge instances' . "\n";

// Get & parse users list
$users = shell_exec('/scripts/listUsers.php');
$users = explode("\n", trim($users));

foreach($users AS $thisUser) {    // Loop users checking their instances
    if (empty($thisUser)) continue;
    if (file_exists("/home/{$thisUser}/www-disabled") or 
        !file_exists("/home/{$thisUser}/www")) {
            echo "User: {$thisUser} is suspended\n";
            passthru("killall -9 -u {$thisUser}");
            continue;  //Suspended
    }

    if (!file_exists("/home/{$thisUser}/.delugeEnable")) continue;  // Deluge not enabled
    
    $instances = shell_exec("pgrep -u{$thisUser} deluged");
    if (empty($instances)) startDeluged($thisUser);
 
    $instancesWeb = shell_exec("pgrep -u{$thisUser} deluge-web");
    if (empty($instancesWeb)) startDelugeWeb($thisUser);

}


function startDeluged($user) {    // this actually calls the function to start rTorrent :)
    echo "Start deluged for user: {$user}\n";
    passthru("su {$user} -c 'cd ~; deluged -l /home/{$user}/.delugeLog -L info'");
}

function startDelugeWeb($user) {
    echo "Start deluge-web for user: {$user}\n";
    passthru("su {$user} -c 'cd ~; deluge-web -l /home/{$user}/.delugeWebLog -L info'");
}
