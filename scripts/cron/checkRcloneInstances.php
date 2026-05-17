#!/usr/bin/env php
<?php
/**
 * Cron task: check Rclone Instances.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../lib/userLifecycle.php';
pmssUserWatchdogRunService('Rclone', 'rcloneEnable', ['rclone'], 'rclone stopped due to suspension', [[
    'processName' => 'rclone',
    'command' => static function (string $thisUser): string {
        $port = (int) file_get_contents("/home/{$thisUser}/.rclonePort");
        return pmssUserWatchdogSuCommand($thisUser, "cd ~; nohup rclone rcd --rc-web-gui --rc-addr 127.0.0.1:{$port} --rc-htpasswd /home/$(whoami)/.lighttpd/.htpasswd --rc-baseurl user-$(whoami)/rclone/ --log-file /home/$(whoami)/.rcloneLog --log-level INFO >> /dev/null 2>&1 &");
    },
    'userLogMessage' => 'rclone start requested',
]]);
