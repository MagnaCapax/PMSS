#!/usr/bin/php
<?php
/**
 * Cron watchdog ensuring each user retains a web GUI entry point.
 *
 * Preconditions:
 *   - User homes live under `/home/<user>` with `www/` mirroring the skeleton.
 *   - A healthy GUI exposes `www/index.php`; missing or zero-byte files are
 *     restored from `/etc/skel/www/index.php`.
 *
 * Future enhancements may add HTTP responsiveness probes; keep the watchdog
 * lightweight and idempotent so it can run every few minutes without churn.
 */
// Check user's GUI index.php
$users = shell_exec('/scripts/listUsers.php');
$users = explode("\n", trim($users));

foreach($users AS $thisUser) {    // Loop users checking their instances
    #TODO(user-logs): log per-user GUI repair actions (restored index.php) to /var/log/pmss/user-<username>.log
    if (empty($thisUser)) continue;
	if (file_exists("/home/{$thisUser}/www-disabled")) continue;	// User suspended

    $file = "/home/{$thisUser}/www/index.php";

    if (!file_exists($file)
		or filesize($file) == 0) {
	        `cp /etc/skel/www/index.php {$file}`;
    }

	#TODO Check responsiveness etc. other common stuff as well.

}
