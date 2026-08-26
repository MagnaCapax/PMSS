#!/usr/bin/env php
<?php
/**
 * Cron watchdog ensuring each user retains a web GUI entry point.
 *
 * Preconditions:
 *   - User homes live under `/home/<user>` with `www/` mirroring the skeleton.
 *   - Healthy managed panel files are present and at least as large as their
 *     skeleton sources; missing, empty, or undersized files are restored.
 *
 * Future enhancements may add HTTP responsiveness probes; keep the watchdog
 * lightweight and idempotent so it can run every few minutes without churn.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/logger.php';
require_once __DIR__.'/../lib/lighttpd/userFileWrite.php';
require_once __DIR__.'/../lib/user/selection.php';
require_once __DIR__.'/../lib/update/users/filesystem.php';
require_once __DIR__.'/../lib/update/users/context.php';
require_once __DIR__.'/../lib/update/users/webRootReconcile.php';

/**
 * Normalize and revalidate a managed-user iterator value before composing paths.
 */
function pmssCheckGuiManagedUserNameNormalize($rawUser): ?string
{
    if (!is_scalar($rawUser)) {
        return null;
    }

    $username = trim((string) $rawUser);
    if (!pmssValidateUsername($username)) {
        return null;
    }

    return pmssNormalizeUsername($username);
}

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

/** Check the small sentinel set before running the complete web-root reconciler. */
function pmssCheckGuiWebRootSentinelsHealthy(string $wwwDir, string $homeDir): bool
{
    foreach ([$wwwDir.'/index.php', $wwwDir.'/rutorrent/index.html'] as $sentinel) {
        if (!pmssCheckGuiUserPathIsSafe($sentinel, $homeDir, false)
            || is_link($sentinel)
            || !is_file($sentinel)
            || (int) @filesize($sentinel) <= 0) {
            return false;
        }
    }

    return true;
}

/** Reconcile only when a critical web-root sentinel is missing or unusable. */
function pmssCheckGuiWebRootReconcileIfSentinelMissing(string $user, string $homeDir, callable $log): bool
{
    if (pmssCheckGuiWebRootSentinelsHealthy($homeDir.'/www', $homeDir)) {
        return true;
    }

    $context = pmssBuildUserWebRootContext($user);
    if ($context === null || rtrim((string) $context['home'], '/') !== rtrim($homeDir, '/')) {
        $log("Skipping {$user}: unable to build a safe web-root reconciliation context");
        return false;
    }

    return pmssUserReconcileWebRoot($context, $log);
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
    if (!pmssDirEnsureExists($directory, 0755)) {
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
 * Restore a managed user panel file when it is missing, empty, or undersized.
 */
function pmssCheckGuiRestoreUserFile(string $targetFile, string $sourceFile, string $user, string $label, callable $log, string $homeDir = ''): bool
{
    $homeDir = $homeDir === '' ? dirname(dirname($targetFile)) : rtrim($homeDir, '/');
    if (!pmssCheckGuiUserPathIsSafe($targetFile, $homeDir, false)) {
        $log("Skipping {$user}: unsafe {$label} target {$targetFile}");
        return false;
    }

    $targetSize = @filesize($targetFile);
    $sourceSize = @filesize($sourceFile);
    if (is_file($targetFile) && is_int($targetSize) && $targetSize > 0
        && (!is_int($sourceSize) || $targetSize >= $sourceSize)) {
        return true;
    }

    if (file_exists($targetFile) && !is_file($targetFile)) {
        $log("Skipping {$user}: expected {$label} file but found non-file at {$targetFile}");
        return false;
    }

    if (
        !pmssPathTargetIsSafe($sourceFile, false, true)
        || !is_file($sourceFile)
        || is_link($sourceFile)
        || !is_int($sourceSize)
        || $sourceSize <= 0
    ) {
        $log("Cannot restore {$label} for {$user}: missing skeleton source {$sourceFile}");
        return false;
    }

    $content = @file_get_contents($sourceFile);
    if (!is_string($content) || strlen($content) !== $sourceSize) {
        $log("Skipping {$user}: unable to read complete {$label} source {$sourceFile}");
        return false;
    }

    $log("Restoring {$label} for user {$user}");
    if (!pmssReplaceUserFilePreservingMetadata($targetFile, $content)) {
        $log("Skipping {$user}: unable to atomically replace {$label} at {$targetFile}");
        return false;
    }

    return pmssCheckGuiApplyOwnership($targetFile, $user, $log);
}

/** Preserve the existing public helper contract for index.php callers. */
function pmssCheckGuiRestoreUserIndex(string $targetFile, string $sourceFile, string $user, callable $log, string $homeDir = ''): bool
{
    return pmssCheckGuiRestoreUserFile($targetFile, $sourceFile, $user, 'index.php', $log, $homeDir);
}

/**
 * Run the GUI watchdog for every managed home user.
 */
function pmssCheckGuiMain(array $argv): int
{
    $logger = new Logger(__FILE__);
    $log = [$logger, 'msg'];
    $skeletonWwwDir = '/etc/skel/www';
    $homeRoot = pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home');

    foreach (pmssManagedHomeUsersList() as $thisUser) {
        $thisUser = pmssCheckGuiManagedUserNameNormalize($thisUser);
        if ($thisUser === null) {
            $logger->msg('Skipping invalid managed-user entry while checking GUI files');
            continue;
        }

        // User suspended check after the iterator value has been revalidated.
        if (file_exists(rtrim($homeRoot, '/')."/{$thisUser}/www-disabled")) continue;

        $homeDir = rtrim($homeRoot, '/')."/{$thisUser}";
        $wwwDir = $homeDir.'/www';
        $dataDir = $homeDir.'/data';

        if (!is_dir($homeDir) || is_link($homeDir)) {
            $logger->msg("Skipping {$thisUser}: home directory missing or unsafe at {$homeDir}");
            continue;
        }

        // The shared reconciler is intentionally gated so healthy users keep
        // the cheap watchdog path while damaged roots converge completely.
        pmssCheckGuiWebRootReconcileIfSentinelMissing($thisUser, $homeDir, $log);

        // Recreate core userspace paths if users accidentally remove them.
        if (!pmssCheckGuiEnsureUserDirectory($wwwDir, $thisUser, 'www', $log, $homeDir)) {
            continue;
        }
        pmssCheckGuiEnsureUserDirectory($dataDir, $thisUser, 'data', $log, $homeDir);

        // Keep the panel entrypoint and its load-bearing include recoverable
        // outside the panel request path after a partial filesystem write.
        foreach (['index.php', 'scriptsInc.php'] as $guiFile) {
            pmssCheckGuiRestoreUserFile(
                $wwwDir.'/'.$guiFile,
                $skeletonWwwDir.'/'.$guiFile,
                $thisUser,
                $guiFile,
                $log,
                $homeDir
            );
        }

        #TODO Check responsiveness etc. other common stuff as well.
    }

    return 0;
}

$pmssCheckGuiScript = $_SERVER['SCRIPT_FILENAME'] ?? '';
if ($pmssCheckGuiScript !== '' && realpath($pmssCheckGuiScript) === realpath(__FILE__)) {
    exit(pmssCheckGuiMain($argv ?? []));
}
