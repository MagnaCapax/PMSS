<?php
/** Web-root account command execution and root-fixture parent guards. @license GPL-3.0-only */

/** Root may act as its own fixture account only beneath unwritable root parents. */
function pmssUserWebRootMigrationRootParentsSafe(string $home, array $paths): bool
{
    foreach ($paths as $path) {
        $parent = dirname($path);
        while (true) {
            if (!pmssUserWebRootMigrationParentSafe($home, $parent)
                || !is_array($stat = @lstat($parent))
                || (int) $stat['uid'] !== 0 || (((int) $stat['mode']) & 0022) !== 0) {
                return false;
            }
            if ($parent === $home) {
                break;
            }
            $parent = dirname($parent);
        }
    }
    return true;
}

/** Run as the account, or as an already unprivileged owner of a fixture home. */
function pmssUserWebRootMigrationRun(string $user, string $home, array $paths, string $command, ?string $input = null): bool
{
    $uid = function_exists('posix_geteuid') ? @posix_geteuid() : -1;
    $account = function_exists('posix_getpwnam') ? @posix_getpwnam($user) : false;
    if ($uid === 0 && is_array($account) && isset($account['uid'])
        && (int) $account['uid'] === 0
        && !pmssUserWebRootMigrationRootParentsSafe($home, $paths)) {
        return false;
    }
    if (pmssAccountPathRun($user, $home, $paths, $command, $input)) {
        return true;
    }
    // Streamed copies need the checked account runner; the legacy fallback has no stdin pipe.
    if ($input !== null) return false;
    if ($uid < 1 || @fileowner($home) !== $uid || !pmssPathTargetIsSafe($home, true)) {
        return false;
    }
    foreach ($paths as $path) {
        if (!pmssPathSegmentsAreSafe($path, false, true)
            || !pmssPathWithinResolvedRoot($path, $home)) {
            return false;
        }
    }
    $process = @proc_open('sh -c '.escapeshellarg($command), [
        0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w'],
    ], $pipes);
    try {
        return is_resource($process) && @proc_close($process) === 0;
    } catch (\Throwable $error) {
        // The legacy fallback owns its child too when the first reap throws.
        if (is_resource($process)) {
            pmssProcessTerminateAndClose($process);
        }
        throw $error;
    }
}
