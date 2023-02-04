#!/usr/bin/php
<?php
echo date('Y-m-d H:i:s') . ': Checking Rclone instances' . "\n";

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

    if (!file_exists("/home/{$thisUser}/.rcloneEnable")) continue;  // Deluge not enabled
    
    $instances = shell_exec('pgrep -u' . $thisUser . ' rclone');
    if (empty($instances)) startRclone($thisUser);
 

}


function startRclone($user) {    // this actually calls the function to start rTorrent :)
    echo "Start rclone for user: {$user}\n";
    $port = (int) file_get_contents( "/home/{$user}/.rclonePort" );
    passthru("su {$user} -c 'cd ~; nohup rclone rcd --rc-web-gui --rc-addr 127.0.0.1:{$port} --rc-htpasswd /home/$(whoami)/.lighttpd/.htpasswd --rc-baseurl user-$(whoami)/rclone/ --log-file /home/$(whoami)/.rcloneLog --log-level INFO >> /dev/null 2>&1 &'");
}

