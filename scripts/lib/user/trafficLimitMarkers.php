<?php
/** Traffic-throttle runtime marker lifecycle. Loaded by trafficLimitThrottle.php.
 * @license GPL-3.0-only
 */

function pmssTrafficLimitLog(string $user, string $message): void
{
    echo pmssLogFormatTimestampedLine($message);
    if (function_exists('pmssUserLog') && ($normalizedUser = pmssTrafficLimitCliUsernameNormalize($user)) !== null) {
        pmssUserLog($normalizedUser, $message);
    }
}

/** Confirm traffic throttle markers cannot follow unsafe path segments. */
function pmssTrafficLimitMarkerPathIsSafe(string $path): bool
{
    return function_exists('pmssUserFilePathIsSafe') && pmssUserFilePathIsSafe($path);
}

/**
 * Refresh the root runtime throttle marker. With $capMbit the marker also records the cap
 * FireQOS applies (networkFireqosUserCaps); ~/.throttle is only its display copy.
 */
function pmssTrafficLimitMarkerTouch(string $user, string $path, ?int $capMbit = null): bool
{
    if (!pmssTrafficLimitMarkerPathIsSafe($path)) {
        pmssTrafficLimitLog($user, "traffic throttle marker path unsafe ({$path})");
        return false;
    }
    if ($capMbit !== null ? !pmssIntegerSettingFileWrite($path, $capMbit) : !@touch($path)) {
        pmssTrafficLimitLog($user, "traffic throttle marker touch failed ({$path})");
        return false;
    }
    if (!@chmod($path, 0600)) {
        pmssTrafficLimitLog($user, "traffic throttle marker chmod failed ({$path})");
    }
    return true;
}

function pmssTrafficLimitMarkerRemove(string $user, string $path): bool
{
    if (!pmssPathExistsOrLink($path)) {
        return true;
    }
    if (!pmssTrafficLimitMarkerPathIsSafe($path)) {
        pmssTrafficLimitLog($user, "traffic throttle marker path unsafe ({$path})");
        return false;
    }
    if (!file_exists($path) || @unlink($path) || !file_exists($path)) {
        return true;
    }
    pmssTrafficLimitLog($user, "traffic throttle marker removal failed ({$path})");
    return false;
}
