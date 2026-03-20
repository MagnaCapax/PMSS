#!/usr/bin/env php
<?php
/**
 * Cron task: check Qbittorrent Instances.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
echo date('Y-m-d H:i:s') . ': Checking qBittorrent instances' . "\n";
if (is_file($pmssUserLogPath = __DIR__.'/../lib/user/log.php')) { require_once $pmssUserLogPath; }
if (is_file($pmssQbittorrentPath = __DIR__.'/../lib/user/qbittorrent.php')) { require_once $pmssQbittorrentPath; }
$canUserLog = function_exists('pmssUserLog');

// Get & parse users list
$users = array_filter(explode("\n", trim((string) shell_exec('/scripts/listUsers.php'))));

foreach($users AS $thisUser) {    // Loop users checking their instances
    if (file_exists("/home/{$thisUser}/www-disabled") or
        !file_exists("/home/{$thisUser}/www")) {
            echo "User: {$thisUser} is suspended\n";
            // Kill only qbittorrent-nox — not all user processes (see GH#210).
            passthru("killall -9 -u ".escapeshellarg($thisUser)." qbittorrent-nox 2>/dev/null");
            if ($canUserLog) { pmssUserLog($thisUser, 'qbittorrent-nox stopped due to suspension'); }
            continue;  //Suspended
    }

    if (!file_exists("/home/{$thisUser}/.qbittorrentEnable")) continue;  // qBittorrent not enabled
    
    // pgrep returns running qbittorrent-nox processes owned by the user
    $instances = shell_exec('pgrep -u' . $thisUser . ' qbittorrent-nox');
    $configChanged = function_exists('pmssQbittorrentApplyUploadThrottle')
        && pmssQbittorrentApplyUploadThrottle($thisUser);
    if ($configChanged && !empty($instances)) {
        passthru('killall -u '.escapeshellarg($thisUser).' -TERM qbittorrent-nox 2>/dev/null');
        if ($canUserLog) { pmssUserLog($thisUser, 'qbittorrent-nox restarted to apply upload throttle'); }
        $instances = '';
    }
    if (empty($instances)) {
        echo "Start qBittorrent for user: {$thisUser}\n";
        passthru("su {$thisUser} -c 'cd ~; nohup qbittorrent-nox -d >> /dev/null 2>&1 &'");
        if ($canUserLog) { pmssUserLog($thisUser, 'qbittorrent-nox start requested'); }
    }
 

}
