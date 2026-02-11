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
$pmssUserLogPath = __DIR__.'/../lib/user/log.php';
if (is_file($pmssUserLogPath)) {
    require_once $pmssUserLogPath;
}

// Get & parse users list
$users = array_filter(explode("\n", trim((string) shell_exec('/scripts/listUsers.php'))));

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
            // Kill only deluged and deluge-web — not all user processes (see GH#210).
            passthru("killall -9 -u ".escapeshellarg($thisUser)." deluged 2>/dev/null; killall -9 -u ".escapeshellarg($thisUser)." deluge-web 2>/dev/null");
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
