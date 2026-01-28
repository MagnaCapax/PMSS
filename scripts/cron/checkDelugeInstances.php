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
$pmssUserLogPath = __DIR__.'/../lib/user/log.php';
if (is_file($pmssUserLogPath)) {
    require_once $pmssUserLogPath;
}

// Get & parse users list
$users = shell_exec('/scripts/listUsers.php');
$users = array_filter(explode("\n", trim($users)));

$startDeluged = static function (string $user): void {
    echo "Start deluged for user: {$user}\n";
    passthru("su {$user} -c 'cd ~; deluged -l /home/{$user}/.delugeLog -L info'");
    if (function_exists('pmssUserLog')) {
        pmssUserLog($user, 'deluged start requested');
    }
};

$startDelugeWeb = static function (string $user): void {
    echo "Start deluge-web for user: {$user}\n";
    passthru("su {$user} -c 'cd ~; deluge-web -l /home/{$user}/.delugeWebLog -L info'");
    if (function_exists('pmssUserLog')) {
        pmssUserLog($user, 'deluge-web start requested');
    }
};

foreach($users AS $thisUser) {    // Loop users checking their instances
    if (file_exists("/home/{$thisUser}/www-disabled") or 
        !file_exists("/home/{$thisUser}/www")) {
            echo "User: {$thisUser} is suspended\n";
            passthru("killall -9 -u {$thisUser}");
            if (function_exists('pmssUserLog')) {
                pmssUserLog($thisUser, 'deluge stopped due to suspension');
            }
            continue;  //Suspended
    }

    if (!file_exists("/home/{$thisUser}/.delugeEnable")) continue;  // Deluge not enabled
    
    $instances = shell_exec("pgrep -u{$thisUser} deluged");
    if (empty($instances)) $startDeluged($thisUser);
 
    $instancesWeb = shell_exec("pgrep -u{$thisUser} deluge-web");
    if (empty($instancesWeb)) $startDelugeWeb($thisUser);

}
