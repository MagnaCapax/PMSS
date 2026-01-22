#!/usr/bin/env php
<?php
echo date('Y-m-d H:i:s') . ': Checking Rclone instances' . "\n";
$pmssUserLogPath = __DIR__.'/../lib/user/log.php';
if (is_file($pmssUserLogPath)) {
    require_once $pmssUserLogPath;
}

// Get & parse users list
$users = shell_exec('/scripts/listUsers.php');
$users = explode("\n", trim($users));

$startRclone = static function (string $user): void {
    echo "Start rclone for user: {$user}\n";
    $port = (int) file_get_contents("/home/{$user}/.rclonePort");
    passthru("su {$user} -c 'cd ~; nohup rclone rcd --rc-web-gui --rc-addr 127.0.0.1:{$port} --rc-htpasswd /home/$(whoami)/.lighttpd/.htpasswd --rc-baseurl user-$(whoami)/rclone/ --log-file /home/$(whoami)/.rcloneLog --log-level INFO >> /dev/null 2>&1 &'");
    if (function_exists('pmssUserLog')) {
        pmssUserLog($user, 'rclone start requested');
    }
};

foreach($users AS $thisUser) {    // Loop users checking their instances
    if (empty($thisUser)) continue;
    if (file_exists("/home/{$thisUser}/www-disabled") or 
        !file_exists("/home/{$thisUser}/www")) {
            echo "User: {$thisUser} is suspended\n";
            passthru("killall -9 -u {$thisUser}");
            if (function_exists('pmssUserLog')) {
                pmssUserLog($thisUser, 'rclone stopped due to suspension');
            }
            continue;  //Suspended
    }

    // Skip users that have not explicitly enabled rclone support
    if (!file_exists("/home/{$thisUser}/.rcloneEnable")) continue;
    
    $instances = shell_exec('pgrep -u' . $thisUser . ' rclone');
    if (empty($instances)) $startRclone($thisUser);

}
