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
    if (!pmssTrafficLimitMarkerPathIsSafe($path)) {
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
    if (!pmssTrafficLimitMarkerPathIsSafe($path)) {
        $error = 'unsafe throttle path';
        return false;
    }
    if (!pmssIntegerSettingFileRemove($path)) {
        $error = 'failed to remove throttle file: '.$path;
        return false;
    }

    return true;
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
    if ($enable
        ? pmssTrafficLimitThrottleFileWrite($throttleFile, (int) $trafficCapMbit, $error)
        : pmssTrafficLimitThrottleFileRemove($throttleFile, $error)) return true;
    pmssTrafficLimitLog($user, ($enable ? 'traffic throttle file write failed (' : 'traffic throttle file removal failed (')
        .($error ?: $throttleFile).')');
    return false;
}

require_once __DIR__.'/trafficLimitMarkers.php';
require_once __DIR__.'/trafficLimitReconcile.php';
