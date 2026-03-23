#!/usr/bin/env php
<?php
/**
 * Cron task: check Rclone Instances.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
echo date('Y-m-d H:i:s') . ': Checking Rclone instances' . "\n";
require_once __DIR__.'/../lib/userLifecycle.php';
pmssUserWatchdogRunEnabledUsers('rcloneEnable', ['rclone'], 'rclone stopped due to suspension', function (string $thisUser): void {
    if (empty(shell_exec('pgrep -u' . $thisUser . ' rclone'))) {
        echo "Start rclone for user: {$thisUser}\n";
        $port = (int) file_get_contents("/home/{$thisUser}/.rclonePort");
        passthru("su {$thisUser} -c 'cd ~; nohup rclone rcd --rc-web-gui --rc-addr 127.0.0.1:{$port} --rc-htpasswd /home/$(whoami)/.lighttpd/.htpasswd --rc-baseurl user-$(whoami)/rclone/ --log-file /home/$(whoami)/.rcloneLog --log-level INFO >> /dev/null 2>&1 &'");
        pmssUserLog($thisUser, 'rclone start requested');
    }
});
