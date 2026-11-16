<?php
/**
 * Cron watchdog ensuring each user retains a web GUI entry point.
 *
 * Preconditions:
 *   - User homes live under `/home/<user>` with `www/` mirroring the skeleton.
 *   - Healthy managed panel files match their skeleton sources; missing or
 *     divergent files are restored.
 *
 * Future enhancements may add HTTP responsiveness probes; keep the watchdog
 * lightweight and idempotent so it can run every few minutes without churn.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

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

    clearstatcache(true, $path);
    return pmssPathTargetIsSafe($path, $directoryTarget, true)
        && pmssPathWithinRootIsSafe(dirname($path), $homeDir, true)
        && !is_link($path)
        && (!file_exists($path) || ($directoryTarget ? is_dir($path) : is_file($path)));
}

/**
 * Confirm a repaired path already belongs to the account that wrote it.
 */
function pmssCheckGuiApplyOwnership(string $path, string $user, callable $log): bool
{
    clearstatcache(true, $path);
    if (is_link($path)) {
        $log("Skipping {$user}: refusing to apply ownership to symlinked {$path}");
        return false;
    }
    if (is_file($path) || is_dir($path)) {
        $account = function_exists('posix_getpwnam') ? @posix_getpwnam($user) : false;
        $stat = @lstat($path);
        if (is_array($account) && is_array($stat)
            && (int) $stat['uid'] === (int) $account['uid']) return true;
    }
    $log("Skipping {$user}: unable to apply ownership to {$path}");
    return false;
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
