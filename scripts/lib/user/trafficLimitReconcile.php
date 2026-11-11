<?php
/** Traffic-throttle recovery and cooldown policy. Loaded by trafficLimitThrottle.php.
 * @license GPL-3.0-only
 */

/**
 * Remove stale persistent throttle state when the runtime enforcement marker is gone.
 */
function pmssTrafficLimitThrottleOrphanReconcile(
    string $user,
    int $trafficCapMbit,
    string $enabledMarkerPath,
    string $homeRoot = '/home'
): bool {
    if (!pmssTrafficLimitMarkerPathIsSafe($enabledMarkerPath)) {
        pmssTrafficLimitLog($user, "traffic throttle marker path unsafe ({$enabledMarkerPath})");
        return false;
    }
    $throttleFile = pmssTrafficLimitThrottleFilePath($user, $homeRoot);
    if (pmssPathExistsOrLink($enabledMarkerPath)
        || $throttleFile === null || !pmssRegularFilePathIsReadable($throttleFile)) {
        return true;
    }

    pmssTrafficLimitLog($user, 'orphaned traffic throttle file removed after runtime marker loss');
    return pmssTrafficLimitThrottleApply($user, $trafficCapMbit, false, $homeRoot);
}

/**
 * Lift an expired throttle: drop the runtime marker, then remove ~/.throttle.
 * Removal does not always take effect on the first attempt, so it runs twice;
 * the result is the second attempt's.
 */
function pmssTrafficLimitThrottleLiftAfterCooldown(string $user, string $enabledMarkerPath, string $reason, string $homeRoot = '/home'): bool
{
    if (!pmssTrafficLimitMarkerRemove($user, $enabledMarkerPath)) {
        return false;
    }
    pmssTrafficLimitLog($user, $reason);
    pmssTrafficLimitThrottleApply($user, 0, false, $homeRoot);
    sleep(1);
    return pmssTrafficLimitThrottleApply($user, 0, false, $homeRoot);
}

/**
 * Reconcile throttle state when usage data is missing or invalid.
 *
 * No data is not "under limit": the payload reader returns null for a
 * permission drift as well as for corrupt data, and the runtime stats copy
 * is gone after every reboot until trafficStats runs again. So lift the
 * throttle only when nothing has reasserted it for a full cooldown. Every
 * over-limit run touches the runtime marker and rewrites ~/.throttle, so
 * the marker's mtime dates the last reassertion, and ~/.throttle's mtime
 * does once the marker (tmpfs) is gone after a reboot.
 */
function pmssTrafficLimitThrottleReconcileWithoutUsage(string $user, string $enabledMarkerPath, int $cooldownSeconds, string $homeRoot = '/home'): bool
{
    if (!pmssTrafficLimitMarkerPathIsSafe($enabledMarkerPath)) {
        pmssTrafficLimitLog($user, "traffic throttle marker path unsafe ({$enabledMarkerPath})");
        return false;
    }
    $hasMarker = pmssPathExistsOrLink($enabledMarkerPath);
    $assertionPath = $enabledMarkerPath;
    if (!$hasMarker) {
        $throttleFile = pmssTrafficLimitThrottleFilePath($user, $homeRoot);
        if ($throttleFile === null || !pmssRegularFilePathIsReadable($throttleFile)) {
            return true;
        }
        $assertionPath = $throttleFile;
    }
    // Marker and post-reboot display copy share the same last-assertion age policy.
    $assertedAt = @filemtime($assertionPath);
    if ($assertedAt === false || (time() - $assertedAt) <= $cooldownSeconds) {
        return true;
    }
    if (!$hasMarker) return pmssTrafficLimitThrottleOrphanReconcile($user, 0, $enabledMarkerPath, $homeRoot);
    return pmssTrafficLimitThrottleLiftAfterCooldown(
        $user,
        $enabledMarkerPath,
        'traffic throttle removed after cooldown (no usage data)',
        $homeRoot
    );
}

/** Lift persisted throttle state when the root-owned traffic limit is cleared. */
function pmssTrafficLimitThrottleClearUnlimited(string $user, string $enabledMarkerPath, string $homeRoot = '/home'): bool
{
    if (!pmssPathExistsOrLink($enabledMarkerPath)) {
        return pmssTrafficLimitThrottleOrphanReconcile($user, 0, $enabledMarkerPath, $homeRoot);
    }
    if (!pmssTrafficLimitMarkerRemove($user, $enabledMarkerPath)) {
        return false;
    }
    pmssTrafficLimitLog($user, 'traffic throttle removed: traffic limit cleared');
    return pmssTrafficLimitThrottleApply($user, 0, false, $homeRoot);
}
