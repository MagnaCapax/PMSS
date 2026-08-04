#!/usr/bin/env php
<?php
/**
 * Cron task: check Rclone Instances.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../lib/user/watchdog.php';

$pmssCheckRcloneLock = pmssLockFileAcquire(pmssRuntimeLockPath('pmss-checkRcloneInstances.lock'), true);
if ($pmssCheckRcloneLock === false) {
    echo date('Y-m-d H:i:s').': checkRcloneInstances already running; skipping' . "\n";
    exit(0);
}

pmssUserWatchdogRunService('Rclone', 'rcloneEnable', ['rclone'], 'rclone stopped due to suspension', [
    pmssUserWatchdogServiceSpec('rclone', static function (string $thisUser): string {
        $port = pmssUserWatchdogLocalPortRead("/home/{$thisUser}/.rclonePort");
        if ($port === null) {
            pmssUserLog($thisUser, 'rclone start skipped due to invalid port');
            return '';
        }

        return pmssUserWatchdogSuCommand($thisUser, "cd ~; nohup rclone rcd --rc-web-gui --rc-addr 127.0.0.1:{$port} --rc-htpasswd /home/$(whoami)/.lighttpd/.htpasswd --rc-baseurl user-$(whoami)/rclone/ --log-file /home/$(whoami)/.rcloneLog --log-level INFO >> /dev/null 2>&1 &");
    }, 'rclone start requested'),
]);
