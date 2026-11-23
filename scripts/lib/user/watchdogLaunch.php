<?php
/**
 * Root-side launch marker for watchdogs that start a daemon asynchronously.
 *
 * A launch that returns OK says nothing about the daemon surviving its own
 * initialisation. The marker records "launched, outcome not yet observed" so
 * the next watchdog tick can tell a first start from a daemon that died after
 * its launch. It lives in the private runtime directory (cleared on reboot),
 * never in an account tree.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/watchdogTimeout.php';

/** Resolve the marker path beside the shared watchdog state, or null when that state dir is unsafe. */
function pmssUserWatchdogLaunchMarkerPath(string $username, string $service): ?string
{
    // Reuse the backoff path's ownership, symlink and permission checks for the directory.
    $statePath = pmssUserWatchdogBackoffPath($username, $service);
    if ($statePath === null) return null;
    $path = dirname($statePath).'/watchdog-launch-'.$username.'-'.$service.'.ts';
    return pmssPathTargetIsSafe($path, false, true) ? $path : null;
}

/** Return the previous launch time, or null when no launch awaits an outcome. */
function pmssUserWatchdogLaunchMarkerRead(string $username, string $service): ?int
{
    $path = pmssUserWatchdogLaunchMarkerPath($username, $service);
    if ($path === null) return null;
    $raw = pmssReadRegularFileContentsVerified($path, null, 32);
    return is_string($raw) && ctype_digit($raw) ? (int) $raw : null;
}

/** Mark a launch whose outcome the next tick must observe; false when it cannot be recorded. */
function pmssUserWatchdogLaunchMarkerWrite(string $username, string $service, ?int $now = null): bool
{
    $path = pmssUserWatchdogLaunchMarkerPath($username, $service);
    if ($path === null || !pmssFileWriteComplete($path, (string) ($now ?? time()), LOCK_EX)) return false;
    @chmod($path, 0600);
    return true;
}

/** Forget a launch once the daemon is observed running. */
function pmssUserWatchdogLaunchMarkerClear(string $username, string $service): void
{
    $path = pmssUserWatchdogLaunchMarkerPath($username, $service);
    if ($path !== null && file_exists($path)) @unlink($path);
}
