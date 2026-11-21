<?php
/** Web-root inode-verified moves, account copies and staging removal. @license GPL-3.0-only */

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
