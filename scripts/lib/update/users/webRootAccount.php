<?php
/** Account-authority commands and inode-checked web-root moves.
 * @license GPL-3.0-only
 */

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

/** Move a directory within one home with the account's authority. */
function pmssUserWebRootMigrationRenameAsAccount(string $user, string $home, string $source, string $target): bool
{
    clearstatcache(true, $source);
    clearstatcache(true, $target);
    if (!pmssUserWebRootMigrationParentSafe($home, dirname($source))
        || !pmssUserWebRootMigrationParentSafe($home, dirname($target))
        || !pmssPathTargetIsSafe($source, true, true) || !is_dir($source)
        || !pmssPathTargetIsSafe($target, true, true)) {
        return false;
    }

    $before = @lstat($source);
    if (!is_array($before)) {
        return false;
    }
    $targetStat = @lstat($target);
    $rootTargetSafe = !is_array($targetStat) || (int) $targetStat['uid'] === 0;
    $command = 'mv -T -- '.escapeshellarg($source).' '.escapeshellarg($target);
    $uid = function_exists('posix_geteuid') ? @posix_geteuid() : -1;
    $account = function_exists('posix_getpwnam') ? @posix_getpwnam($user) : false;
    // The caller already has account authority; PHP rename preserves the
    // existing empty-directory replacement used by full restore.
    $sameAccount = $uid > 0 && is_array($account) && isset($account['uid'])
        && (int) $account['uid'] === $uid
        && @fileowner($home) === $uid;
    $rootAccount = $uid === 0 && is_array($account) && isset($account['uid'])
        && (int) $account['uid'] === 0
        && (int) $before['uid'] === 0
        && $rootTargetSafe
        && pmssUserWebRootMigrationRootParentsSafe($home, [$source, $target]);
    $moved = false;
    if ($sameAccount || $rootAccount) {
        $moved = @rename($source, $target);
    } elseif ($uid !== 0 || !is_array($account) || !isset($account['uid']) || (int) $account['uid'] !== 0) {
        $moved = pmssUserWebRootMigrationRun($user, $home, [$source, $target], $command);
    }
    if (!$moved && dirname($source) === $home && dirname($target) === $home) {
        // A root-owned, non-writable home cannot have its direct children
        // substituted by the tenant while root performs watchdog recovery.
        $homeStat = @lstat($home);
        $moved = function_exists('posix_geteuid') && @posix_geteuid() === 0
            && (int) $before['uid'] === 0
            && $rootTargetSafe
            && is_array($homeStat) && (int) $homeStat['uid'] === 0
            && (((int) $homeStat['mode']) & 0022) === 0
            && pmssPathTargetIsSafe($home, true)
            && pmssPathTargetIsSafe($source, true, true)
            && pmssPathTargetIsSafe($target, true, true)
            && is_array($current = @lstat($source))
            && pmssFilesystemStatSameInode($current, $before)
            && @rename($source, $target);
    }
    clearstatcache(true, $target);
    $after = $moved ? @lstat($target) : false;
    return is_array($after) && !is_link($target) && pmssFilesystemStatSameInode($after, $before);
}

/** Copy and remove migration staging only with the account's authority. */
function pmssUserWebRootMigrationCopyTree(string $user, string $home, string $source, string $target): bool
{
    return !is_link($source) && !pmssPathExistsOrLink($target)
        && pmssUserWebRootMigrationRun($user, $home, [$source, $target],
            'cp -aT -- '.escapeshellarg($source).' '.escapeshellarg($target));
}

/** Remove migration staging only with the account's authority. */
function pmssUserWebRootMigrationRemoveCopy(string $user, string $home, string $path): void
{
    $command = 'rm -rf -- '.escapeshellarg($path);
    pmssUserWebRootMigrationRun($user, $home, [$path], $command);
}
