#!/usr/bin/env php
<?php
echo date('Y-m-d H:i:s') . ': Checking qBittorrent instances' . "\n";

// Get & parse users list
$users = shell_exec('/scripts/listUsers.php');
$users = explode("\n", trim($users));

$startQbittorrent = static function (string $user): void {
    echo "Start qBittorrent for user: {$user}\n";
    passthru("su {$user} -c 'cd ~; nohup qbittorrent-nox -d >> /dev/null 2>&1 &'");
};

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

    if (!file_exists("/home/{$thisUser}/.qbittorrentEnable")) continue;  // qBittorrent not enabled
    
    // pgrep returns running qbittorrent-nox processes owned by the user
    $instances = shell_exec('pgrep -u' . $thisUser . ' qbittorrent-nox');
    if (empty($instances)) $startQbittorrent($thisUser);
    #TODO(user-logs): record qbittorrent start in per-user log
 

}
