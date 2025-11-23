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

require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/users.php';

$logger = new Logger(__FILE__);

// Get & parse users list
$users = users::listHomeUsers();

foreach($users as $thisUser) {    // Loop users checking their instances
    if (empty($thisUser)) continue;
    if (file_exists("/home/{$thisUser}/www-disabled")) continue;	// User suspended

    $file = "/home/{$thisUser}/www/index.php";

    if (!file_exists($file)
		or filesize($file) == 0) {
            $logger->msg("Restoring index.php for user {$thisUser}");
	        runCommand("cp /etc/skel/www/index.php " . escapeshellarg($file), false, [$logger, 'msg']);
    }

	#TODO Check responsiveness etc. other common stuff as well.

}
