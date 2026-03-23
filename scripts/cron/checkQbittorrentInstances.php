#!/usr/bin/env php
<?php
/**
 * Cron task: check Qbittorrent Instances.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
echo date('Y-m-d H:i:s') . ': Checking qBittorrent instances' . "\n";
require_once __DIR__.'/../lib/userLifecycle.php';
if (is_file($pmssQbittorrentPath = __DIR__.'/../lib/user/qbittorrent.php')) { require_once $pmssQbittorrentPath; }
$users = pmssListManagedUsers();
foreach($users AS $thisUser) {
    if (pmssUserWebRootUnavailable($thisUser)) {
            echo "User: {$thisUser} is suspended\n";
            // Kill only qbittorrent-nox — not all user processes (see GH#210).
            passthru("killall -9 -u ".escapeshellarg($thisUser)." qbittorrent-nox 2>/dev/null");
            pmssUserLog($thisUser, 'qbittorrent-nox stopped due to suspension');
            continue;  //Suspended
    }

    if (!file_exists("/home/{$thisUser}/.qbittorrentEnable")) continue;  // qBittorrent not enabled
    
    // pgrep returns running qbittorrent-nox processes owned by the user
    $instances = shell_exec('pgrep -u' . $thisUser . ' qbittorrent-nox');
    if (function_exists('pmssQbittorrentApplyUploadThrottle') && pmssQbittorrentApplyUploadThrottle($thisUser) && !empty($instances)) {
        passthru('killall -u '.escapeshellarg($thisUser).' -TERM qbittorrent-nox 2>/dev/null');
        pmssUserLog($thisUser, 'qbittorrent-nox restarted to apply upload throttle');
        $instances = '';
    }
    if (empty($instances)) {
        echo "Start qBittorrent for user: {$thisUser}\n";
        passthru("su {$thisUser} -c 'cd ~; nohup qbittorrent-nox -d >> /dev/null 2>&1 &'");
        pmssUserLog($thisUser, 'qbittorrent-nox start requested');
    }
 

}
