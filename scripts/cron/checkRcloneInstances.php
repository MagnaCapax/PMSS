#!/usr/bin/env php
<?php
/**
 * Cron task: check Rclone Instances.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
echo date('Y-m-d H:i:s') . ': Checking Rclone instances' . "\n";
$pmssUserLogPath = __DIR__.'/../lib/user/log.php';
if (is_file($pmssUserLogPath)) {
    require_once $pmssUserLogPath;
}

// Get & parse users list
$users = array_filter(explode("\n", trim((string) shell_exec('/scripts/listUsers.php'))));

foreach($users AS $thisUser) {    // Loop users checking their instances
    if (file_exists("/home/{$thisUser}/www-disabled") or
        !file_exists("/home/{$thisUser}/www")) {
            echo "User: {$thisUser} is suspended\n";
            // Kill only rclone — not all user processes (see GH#210).
            passthru("killall -9 -u ".escapeshellarg($thisUser)." rclone 2>/dev/null");
            if (function_exists('pmssUserLog')) {
                pmssUserLog($thisUser, 'rclone stopped due to suspension');
            }
            continue;  //Suspended
    }

    // Skip users that have not explicitly enabled rclone support
    if (!file_exists("/home/{$thisUser}/.rcloneEnable")) continue;
    
    $instances = shell_exec('pgrep -u' . $thisUser . ' rclone');
    if (empty($instances)) {
        echo "Start rclone for user: {$thisUser}\n";
        $port = (int) file_get_contents("/home/{$thisUser}/.rclonePort");
        passthru("su {$thisUser} -c 'cd ~; nohup rclone rcd --rc-web-gui --rc-addr 127.0.0.1:{$port} --rc-htpasswd /home/$(whoami)/.lighttpd/.htpasswd --rc-baseurl user-$(whoami)/rclone/ --log-file /home/$(whoami)/.rcloneLog --log-level INFO >> /dev/null 2>&1 &'");
        if (function_exists('pmssUserLog')) {
            pmssUserLog($thisUser, 'rclone start requested');
        }
    }

}
