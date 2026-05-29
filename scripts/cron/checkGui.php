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
require_once __DIR__.'/../lib/user/selection.php';

/**
 * Ensure a required user directory exists with expected ownership.
 *
 * Returns false only when a conflicting non-directory path blocks recovery.
 */
function pmssCheckGuiEnsureUserDirectory(string $directory, string $user, string $label, callable $log): bool
{
    if (is_dir($directory)) {
        return true;
    }

    if (file_exists($directory)) {
        $log("Skipping {$user}: expected {$label} directory but found non-directory at {$directory}");
        return false;
    }

    $log("Restoring {$label} directory for user {$user}");
    runCommand('mkdir -p '.escapeshellarg($directory), false, $log);
    runCommand('chown '.escapeshellarg($user.':'.$user).' '.escapeshellarg($directory), false, $log);
    runCommand('chmod 0755 '.escapeshellarg($directory), false, $log);

    return is_dir($directory);
}

/**
 * Restore the user's GUI entrypoint when it is missing or empty.
 */
function pmssCheckGuiRestoreUserIndex(string $targetFile, string $sourceFile, string $user, callable $log): void
{
    if (is_file($targetFile) && filesize($targetFile) > 0) {
        return;
    }

    if (file_exists($targetFile) && !is_file($targetFile)) {
        $log("Skipping {$user}: expected index.php file but found non-file at {$targetFile}");
        return;
    }

    if (!is_file($sourceFile) || filesize($sourceFile) === 0) {
        $log("Cannot restore index.php for {$user}: missing skeleton source {$sourceFile}");
        return;
    }

    $log("Restoring index.php for user {$user}");
    runCommand('cp '.escapeshellarg($sourceFile).' '.escapeshellarg($targetFile), false, $log);
    runCommand('chown '.escapeshellarg($user.':'.$user).' '.escapeshellarg($targetFile), false, $log);
}

$logger = new Logger(__FILE__);
$log = [$logger, 'msg'];
$skeletonIndex = '/etc/skel/www/index.php';

foreach (pmssManagedHomeUsersList() as $thisUser) {
    // User suspended check (skip empty usernames too).
    if (empty($thisUser) || file_exists("/home/{$thisUser}/www-disabled")) continue;

    $homeDir = "/home/{$thisUser}";
    $wwwDir = $homeDir.'/www';
    $dataDir = $homeDir.'/data';

    if (!is_dir($homeDir)) {
        $logger->msg("Skipping {$thisUser}: home directory missing at {$homeDir}");
        continue;
    }

	// Recreate core userspace paths if users accidentally remove them.
    if (!pmssCheckGuiEnsureUserDirectory($wwwDir, $thisUser, 'www', $log)) {
        continue;
    }
    pmssCheckGuiEnsureUserDirectory($dataDir, $thisUser, 'data', $log);

    // Keep a functioning GUI entrypoint for each non-suspended account.
    pmssCheckGuiRestoreUserIndex($wwwDir.'/index.php', $skeletonIndex, $thisUser, $log);

	#TODO Check responsiveness etc. other common stuff as well.

}
