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
require_once __DIR__.'/../lib/lighttpd/userFileWrite.php';
require_once __DIR__.'/../lib/user/selection.php';

/**
 * Confirm a GUI repair target stays inside the expected home directory.
 */
function pmssCheckGuiUserPathIsSafe(string $path, string $homeDir, bool $directoryTarget): bool
{
    $homeDir = rtrim($homeDir, '/');
    if ($homeDir === '' || !pmssPathTargetIsSafe($homeDir, true)) {
        return false;
    }

    return pmssPathTargetIsSafe($path, $directoryTarget, true)
        && pmssPathWithinRootIsSafe(dirname($path), $homeDir, true);
}

/**
 * Apply ownership metadata for a repaired user path.
 */
function pmssCheckGuiApplyOwnership(string $path, string $user, callable $log): bool
{
    if (function_exists('posix_geteuid') && @posix_geteuid() === 0) {
        if (!@chown($path, $user) || !@chgrp($path, $user)) {
            $log("Skipping {$user}: unable to apply ownership to {$path}");
            return false;
        }
    }

    return true;
}

/**
 * Ensure a required user directory exists with expected ownership.
 *
 * Returns false only when a conflicting non-directory path blocks recovery.
 */
function pmssCheckGuiEnsureUserDirectory(string $directory, string $user, string $label, callable $log, string $homeDir = ''): bool
{
    $homeDir = $homeDir === '' ? dirname($directory) : rtrim($homeDir, '/');
    if (!pmssCheckGuiUserPathIsSafe($directory, $homeDir, true)) {
        $log("Skipping {$user}: unsafe {$label} directory target {$directory}");
        return false;
    }

    if (is_dir($directory)) {
        return true;
    }

    if (file_exists($directory)) {
        $log("Skipping {$user}: expected {$label} directory but found non-directory at {$directory}");
        return false;
    }

    $log("Restoring {$label} directory for user {$user}");
    if (!@mkdir($directory, 0755, true) && !is_dir($directory)) {
        $log("Skipping {$user}: unable to create {$label} directory at {$directory}");
        return false;
    }
    if (!@chmod($directory, 0755)) {
        $log("Skipping {$user}: unable to apply mode to {$label} directory at {$directory}");
        return false;
    }
    if (!pmssCheckGuiApplyOwnership($directory, $user, $log)) {
        return false;
    }

    return is_dir($directory);
}

/**
 * Restore the user's GUI entrypoint when it is missing or empty.
 */
function pmssCheckGuiRestoreUserIndex(string $targetFile, string $sourceFile, string $user, callable $log, string $homeDir = ''): bool
{
    $homeDir = $homeDir === '' ? dirname(dirname($targetFile)) : rtrim($homeDir, '/');
    if (!pmssCheckGuiUserPathIsSafe($targetFile, $homeDir, false)) {
        $log("Skipping {$user}: unsafe index.php target {$targetFile}");
        return false;
    }

    if (is_file($targetFile) && filesize($targetFile) > 0) {
        return true;
    }

    if (file_exists($targetFile) && !is_file($targetFile)) {
        $log("Skipping {$user}: expected index.php file but found non-file at {$targetFile}");
        return false;
    }

    $sourceSize = @filesize($sourceFile);
    if (
        !pmssPathTargetIsSafe($sourceFile, false, true)
        || !is_file($sourceFile)
        || is_link($sourceFile)
        || !is_int($sourceSize)
        || $sourceSize <= 0
    ) {
        $log("Cannot restore index.php for {$user}: missing skeleton source {$sourceFile}");
        return false;
    }

    $log("Restoring index.php for user {$user}");
    if (!@copy($sourceFile, $targetFile)) {
        $log("Skipping {$user}: unable to copy index.php to {$targetFile}");
        return false;
    }

    return pmssCheckGuiApplyOwnership($targetFile, $user, $log);
}

/**
 * Run the GUI watchdog for every managed home user.
 */
function pmssCheckGuiMain(array $argv): int
{
    $logger = new Logger(__FILE__);
    $log = [$logger, 'msg'];
    $skeletonIndex = '/etc/skel/www/index.php';

    foreach (pmssManagedHomeUsersList() as $thisUser) {
        // User suspended check (skip empty usernames too).
        if (empty($thisUser) || file_exists("/home/{$thisUser}/www-disabled")) continue;

        $homeDir = "/home/{$thisUser}";
        $wwwDir = $homeDir.'/www';
        $dataDir = $homeDir.'/data';

        if (!is_dir($homeDir) || is_link($homeDir)) {
            $logger->msg("Skipping {$thisUser}: home directory missing or unsafe at {$homeDir}");
            continue;
        }

        // Recreate core userspace paths if users accidentally remove them.
        if (!pmssCheckGuiEnsureUserDirectory($wwwDir, $thisUser, 'www', $log, $homeDir)) {
            continue;
        }
        pmssCheckGuiEnsureUserDirectory($dataDir, $thisUser, 'data', $log, $homeDir);

        // Keep a functioning GUI entrypoint for each non-suspended account.
        pmssCheckGuiRestoreUserIndex($wwwDir.'/index.php', $skeletonIndex, $thisUser, $log, $homeDir);

        #TODO Check responsiveness etc. other common stuff as well.
    }

    return 0;
}

$pmssCheckGuiScript = $_SERVER['SCRIPT_FILENAME'] ?? '';
if ($pmssCheckGuiScript !== '' && realpath($pmssCheckGuiScript) === realpath(__FILE__)) {
    exit(pmssCheckGuiMain($argv ?? []));
}
