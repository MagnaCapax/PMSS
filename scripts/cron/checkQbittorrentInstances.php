#!/usr/bin/env php
<?php
/**
 * Cron task: check Qbittorrent Instances.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
echo date('Y-m-d H:i:s') . ': Checking qBittorrent instances' . "\n";
$pmssUserLogPath = __DIR__.'/../lib/user/log.php';
if (is_file($pmssUserLogPath)) {
    require_once $pmssUserLogPath;
}
$qbittorrentHelper = __DIR__.'/../lib/user/qbittorrent.php';
if (is_file($qbittorrentHelper)) {
    require_once $qbittorrentHelper;
}

// Get & parse users list
$users = array_filter(explode("\n", trim((string) shell_exec('/scripts/listUsers.php'))));

$startQbittorrent = static function (string $user): void {
    echo "Start qBittorrent for user: {$user}\n";
    passthru("su {$user} -c 'cd ~; nohup qbittorrent-nox -d >> /dev/null 2>&1 &'");
    if (function_exists('pmssUserLog')) {
        pmssUserLog($user, 'qbittorrent-nox start requested');
    }
};

foreach($users AS $thisUser) {    // Loop users checking their instances
    if (file_exists("/home/{$thisUser}/www-disabled") or
        !file_exists("/home/{$thisUser}/www")) {
            echo "User: {$thisUser} is suspended\n";
            // Kill only qbittorrent-nox — not all user processes (see GH#210).
            passthru("killall -9 -u ".escapeshellarg($thisUser)." qbittorrent-nox 2>/dev/null");
            if (function_exists('pmssUserLog')) {
                pmssUserLog($thisUser, 'qbittorrent-nox stopped due to suspension');
            }
            continue;  //Suspended
    }

    if (!file_exists("/home/{$thisUser}/.qbittorrentEnable")) continue;  // qBittorrent not enabled
    
    // pgrep returns running qbittorrent-nox processes owned by the user
    $instances = shell_exec('pgrep -u' . $thisUser . ' qbittorrent-nox');
    $configChanged = false;
    if (function_exists('pmssQbittorrentApplyUploadThrottle')) {
        $configChanged = pmssQbittorrentApplyUploadThrottle($thisUser);
    }
    if ($configChanged && !empty($instances)) {
        passthru('killall -u '.escapeshellarg($thisUser).' -TERM qbittorrent-nox 2>/dev/null');
        if (function_exists('pmssUserLog')) {
            pmssUserLog($thisUser, 'qbittorrent-nox restarted to apply upload throttle');
        }
        $instances = '';
    }
    if (empty($instances)) $startQbittorrent($thisUser);
 

}
