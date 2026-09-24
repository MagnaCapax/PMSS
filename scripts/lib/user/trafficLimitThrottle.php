<?php
/** Traffic throttle files, markers, and orphan reconciliation. Loaded by trafficLimit.php.
 * @license GPL-3.0-only
 */

/**
 * Persist the active traffic throttle cap using the shared safe file writer.
 */
function pmssTrafficLimitThrottleFileWrite(string $path, int $capMbit, ?string &$error = null): bool
{
    $error = null;
    if ($capMbit < 0) {
        $error = 'invalid throttle cap';
        return false;
    }
    if (!function_exists('pmssUserFilePathIsSafe') || !pmssUserFilePathIsSafe($path)) {
        $error = 'unsafe throttle path';
        return false;
    }
    if (!pmssIntegerSettingFileWrite($path, $capMbit)) {
        $error = 'failed to write throttle file: '.$path;
        return false;
    }
    if (!pmssIntegerSettingPathModeConverge($path, 0644)) {
        $error = 'failed to secure throttle file: '.$path;
        return false;
    }

    return true;
}

/**
 * Remove the active traffic throttle cap only after validating the target.
 */
function pmssTrafficLimitThrottleFileRemove(string $path, ?string &$error = null): bool
{
    $error = null;
    if (!function_exists('pmssUserFilePathIsSafe') || !pmssUserFilePathIsSafe($path)) {
        $error = 'unsafe throttle path';
        return false;
    }
    if (!pmssIntegerSettingFileRemove($path)) {
        $error = 'failed to remove throttle file: '.$path;
        return false;
    }

    return true;
}

function pmssTrafficLimitLog(string $user, string $message): void
{
    echo date('Y-m-d H:i:s') . ": {$message}\n";
    if (function_exists('pmssUserLog') && ($normalizedUser = pmssTrafficLimitCliUsernameNormalize($user)) !== null) {
        pmssUserLog($normalizedUser, $message);
    }
}

/** Confirm traffic throttle markers cannot follow unsafe path segments. */
function pmssTrafficLimitMarkerPathIsSafe(string $path): bool
{
    return function_exists('pmssUserFilePathIsSafe') && pmssUserFilePathIsSafe($path);
}

function pmssTrafficLimitMarkerTouch(string $user, string $path): bool
{
    if (!pmssTrafficLimitMarkerPathIsSafe($path)) {
        pmssTrafficLimitLog($user, "traffic throttle marker path unsafe ({$path})");
        return false;
    }
    if (!@touch($path)) {
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
    if (!file_exists($path) && !is_link($path)) {
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

function pmssTrafficLimitThrottleFilePath(string $user, string $homeRoot = '/home'): ?string
{
    $user = pmssTrafficLimitCliUsernameNormalize($user) ?? '';
    if ($user === '') {
        return null;
    }
    $home = rtrim($homeRoot, '/').'/'.$user;
    if (!is_dir($home) || is_link($home) || @realpath($home) !== $home) {
        return null;
    }
    $path = $home.'/.throttle';
    return (!function_exists('pmssUserFilePathIsSafe') || pmssUserFilePathIsSafe($path)) ? $path : null;
}

function pmssTrafficLimitThrottleApply(string $user, int $trafficCapMbit, bool $enable = true, string $homeRoot = '/home'): bool
{
    $throttleFile = pmssTrafficLimitThrottleFilePath($user, $homeRoot);
    if ($throttleFile === null) {
        return false;
    }
    $error = null;
    if (!$enable) {
        if (!pmssTrafficLimitThrottleFileRemove($throttleFile, $error)) {
            pmssTrafficLimitLog($user, 'traffic throttle file removal failed ('.($error ?: $throttleFile).')');
            return false;
        }
        return true;
    }
    if (!pmssTrafficLimitThrottleFileWrite($throttleFile, (int) $trafficCapMbit, $error)) {
        pmssTrafficLimitLog($user, 'traffic throttle file write failed ('.($error ?: $throttleFile).')');
        return false;
    }
    return true;
}

/**
 * Check whether the per-user throttle cap exists as a safe regular file.
 */
function pmssTrafficLimitThrottleFileExists(string $user, string $homeRoot = '/home'): bool
{
    $throttleFile = pmssTrafficLimitThrottleFilePath($user, $homeRoot);
    return $throttleFile !== null && pmssRegularFilePathIsReadable($throttleFile);
}

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
    if (file_exists($enabledMarkerPath) || is_link($enabledMarkerPath) || !pmssTrafficLimitThrottleFileExists($user, $homeRoot)) {
        return true;
    }

    pmssTrafficLimitLog($user, 'orphaned traffic throttle file removed after runtime marker loss');
    return pmssTrafficLimitThrottleApply($user, $trafficCapMbit, false, $homeRoot);
}
