#!/usr/bin/php
<?php
echo date('Y-m-d H:i:s') . ': Checking qBittorrent instances' . "\n";

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

    if (!file_exists("/home/{$thisUser}/.qbittorrentEnable")) continue;  // Deluge not enabled
    
    $instances = shell_exec('pgrep -u' . $thisUser . ' qbittorrent-nox');
    if (empty($instances)) startQbittorrent($thisUser);
 

}


function startQbittorrent($user) {    // this actually calls the function to start rTorrent :)
    echo "Start qBittorrent for user: {$user}\n";
    passthru("su {$user} -c 'cd ~; nohup qbittorrent-nox -d >> /dev/null 2>&1 &'");
}

