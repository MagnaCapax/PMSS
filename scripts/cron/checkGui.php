#!/usr/bin/env php
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
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/users.php';

$logger = new Logger(__FILE__);

// Get & parse users list using the robust library method
foreach(users::listHomeUsers() as $thisUser) {    // Loop users checking their instances
    // User suspended check (skip empty usernames too).
    if (empty($thisUser) || file_exists("/home/{$thisUser}/www-disabled")) continue;

    $file = "/home/{$thisUser}/www/index.php";

    // Check if GUI entrypoint is missing or empty
    if (!file_exists($file) || filesize($file) == 0) {
        $logger->msg("Restoring index.php for user {$thisUser}");
        // Use runCommand for safe execution and logging
        runCommand("cp /etc/skel/www/index.php " . escapeshellarg($file), false, [$logger, 'msg']);
        
        // Ensure correct permissions after copy
        runCommand("chown " . escapeshellarg("{$thisUser}:{$thisUser}") . " " . escapeshellarg($file), false, [$logger, 'msg']);
    }

	#TODO Check responsiveness etc. other common stuff as well.

}
