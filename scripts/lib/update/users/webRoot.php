<?php
/**
 * Customer-state migration and account mutation boundary for web-root recovery.
 *
 * Move mutable web content below the durable home paths before linking it back
 * into www. The move is deliberately conservative: conflicts and symlinks are
 * left untouched so a later reconciliation pass cannot lose customer data.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__, 2).'/pathSafety.php';
require_once dirname(__DIR__, 2).'/runtime/filesystem.php';
require_once dirname(__DIR__, 2).'/user/directories.php';
require_once dirname(__DIR__, 2).'/lighttpd/accountPath.php';
require_once __DIR__.'/webRootInspection.php';
require_once __DIR__.'/webRootPaths.php';

/** Emit one migration message through the normal updater logger or a test logger. */
function pmssUserWebRootMigrationLog(string $user, ?callable $logger, string $message): void
{
    $message = "[user:{$user}] {$message}";
    if ($logger !== null) {
        $logger($message);
        return;
    }

    logMessage($message);
}

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
function pmssUserWebRootMigrationRun(string $user, string $home, array $paths, string $command): bool
{
    $uid = function_exists('posix_geteuid') ? @posix_geteuid() : -1;
    $account = function_exists('posix_getpwnam') ? @posix_getpwnam($user) : false;
    if ($uid === 0 && is_array($account) && isset($account['uid'])
        && (int) $account['uid'] === 0
        && !pmssUserWebRootMigrationRootParentsSafe($home, $paths)) {
        return false;
    }
    if (pmssAccountPathRun($user, $home, $paths, $command)) {
        return true;
    }
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
    return is_resource($process) && @proc_close($process) === 0;
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

/** Copy a pre-#727 basename-collapsed userTransfer tree into durable storage. */
function pmssUserMigrateWebRootBasenameCollapsePath(
    string $user,
    string $home,
    string $sourceRelative,
    string $targetRelative,
    string $misfileRelative,
    callable $logger
): void {
    $home = rtrim($home, '/');
    $source = $home.'/'.$sourceRelative;
    $target = $home.'/'.$targetRelative;
    $misfile = $home.'/'.$misfileRelative;
    if (!pmssPathExistsOrLink($misfile)) {
        return;
    }

    $linkTarget = pmssUserWebRootMigrationRelativeTarget($home, $source, $target);
    if ($linkTarget === null
        || !pmssUserWebRootMigrationParentSafe($home, dirname($source))
        || !pmssUserWebRootMigrationParentSafe($home, dirname($target))
        || !pmssUserWebRootMigrationParentSafe($home, dirname($misfile))) {
        pmssUserWebRootMigrationLog($user, $logger, 'Refusing unsafe web-root migration path: '.$misfileRelative);
        return;
    }

    if (is_link($source)) {
        if (readlink($source) !== $linkTarget) {
            return;
        }
    } elseif (file_exists($source)) {
        return;
    }

    if (($conflict = pmssUserWebRootMigrationDestinationConflict($target, $targetRelative, $sourceRelative, true)) !== null) {
        pmssUserWebRootMigrationLog($user, $logger, $conflict);
        return;
    }

    $before = pmssUserWebRootMigrationSnapshot($misfile);
    if ($before === null) {
        pmssUserWebRootMigrationLog($user, $logger, 'Refusing unsafe symlink or unreadable tree at '.$misfileRelative);
        return;
    }
    if (!pmssUserWebRootMigrationPrepareParent($user, $home, dirname($target), $logger)) {
        return;
    }

    $temporary = dirname($target).'/.'.basename($target).'.pmss-misfile-copy-'.getmypid().'-'.bin2hex(random_bytes(6));
    if (!pmssUserWebRootMigrationCopyTree($user, $home, $misfile, $temporary)) {
        pmssUserWebRootMigrationRemoveCopy($user, $home, $temporary);
        pmssUserWebRootMigrationLog($user, $logger, 'Unable to copy misfiled '.$misfileRelative.' to durable storage');
        return;
    }

    $after = pmssUserWebRootMigrationSnapshot($temporary);
    if ($after === null || $before !== $after) {
        pmssUserWebRootMigrationRemoveCopy($user, $home, $temporary);
        pmssUserWebRootMigrationLog($user, $logger, 'Verification failed; removed incomplete copy for '.$misfileRelative);
        return;
    }
    if (($conflict = pmssUserWebRootMigrationDestinationConflict($target, $targetRelative, $sourceRelative, false)) !== null) {
        pmssUserWebRootMigrationRemoveCopy($user, $home, $temporary);
        pmssUserWebRootMigrationLog($user, $logger, $conflict);
        return;
    }
    if (file_exists($target)
        && (!pmssUserWebRootMigrationDirectoryIsEmpty($target)
            || !pmssUserWebRootMigrationRun($user, $home, [$target], 'rmdir -- '.escapeshellarg($target)))) {
        pmssUserWebRootMigrationRemoveCopy($user, $home, $temporary);
        pmssUserWebRootMigrationLog($user, $logger, 'Preserving destination conflict for '.$sourceRelative);
        return;
    }

    if (!pmssUserWebRootMigrationRenameAsAccount($user, $home, $temporary, $target)) {
        pmssUserWebRootMigrationRemoveCopy($user, $home, $temporary);
        pmssUserWebRootMigrationLog($user, $logger, 'Unable to copy misfiled '.$misfileRelative.' to durable storage');
        return;
    }

    pmssUserWebRootMigrationLog($user, $logger, 'Copied misfiled '.$misfileRelative.' to durable storage for '.$sourceRelative);
}

/** Migrate one customer-owned path, refusing conflicts and unsafe trees. */
function pmssUserMigrateWebRootPath(
    string $user,
    string $home,
    string $sourceRelative,
    string $targetRelative,
    callable $logger
): void {
    $home = rtrim($home, '/');
    $source = $home.'/'.$sourceRelative;
    $target = $home.'/'.$targetRelative;
    $sourceParent = dirname($source);
    $targetParent = dirname($target);
    $linkTarget = pmssUserWebRootMigrationRelativeTarget($home, $source, $target);
    if ($linkTarget === null
        || !pmssUserWebRootMigrationParentSafe($home, $sourceParent)
        || !pmssUserWebRootMigrationParentSafe($home, $targetParent)) {
        pmssUserWebRootMigrationLog($user, $logger, 'Refusing unsafe web-root migration path: '.$sourceRelative);
        return;
    }

    if (is_link($source)) {
        if (readlink($source) === $linkTarget && is_dir($target) && !is_link($target)) {
            return;
        }
        pmssUserWebRootMigrationLog($user, $logger, 'Refusing unexpected symlink at '.$sourceRelative);
        return;
    }

    $sourceExists = file_exists($source);
    if ($sourceExists && !is_dir($source)) {
        pmssUserWebRootMigrationLog($user, $logger, 'Refusing non-directory source at '.$sourceRelative);
        return;
    }
    if (($conflict = pmssUserWebRootMigrationDestinationConflict($target, $targetRelative, $sourceRelative, false)) !== null) {
        pmssUserWebRootMigrationLog($user, $logger, $conflict);
        return;
    }
    $targetExists = file_exists($target);
    if (!$sourceExists && !$targetExists) {
        return;
    }
    if ($sourceExists && $targetExists) {
        pmssUserWebRootMigrationLog($user, $logger, 'Preserving destination conflict for '.$sourceRelative);
        return;
    }

    if (!$sourceExists) {
        if (!pmssUserWebRootMigrationPrepareParent($user, $home, $sourceParent, $logger)
            || pmssPathExistsOrLink($source)
            || !pmssUserWebRootMigrationRun($user, $home, [$source],
                'ln -sT -- '.escapeshellarg($linkTarget).' '.escapeshellarg($source))) {
            pmssUserWebRootMigrationLog($user, $logger, 'Unable to restore symlink for '.$sourceRelative);
            return;
        }
        pmssUserWebRootMigrationLog($user, $logger, 'Restored symlink for '.$sourceRelative);
        return;
    }

    $before = pmssUserWebRootMigrationSnapshot($source, true);
    if ($before === null) {
        pmssUserWebRootMigrationLog($user, $logger, 'Refusing unsafe symlink or unreadable tree at '.$sourceRelative);
        return;
    }
    if (!pmssUserWebRootMigrationPrepareParent($user, $home, $targetParent, $logger)
        || pmssPathExistsOrLink($target)
        || !pmssUserWebRootMigrationRenameAsAccount($user, $home, $source, $target)) {
        pmssUserWebRootMigrationLog($user, $logger, 'Unable to move '.$sourceRelative.' to durable storage');
        return;
    }

    $after = pmssUserWebRootMigrationSnapshot($target, true);
    if ($after === null || $before !== $after) {
        $restored = !pmssPathExistsOrLink($source)
            && pmssUserWebRootMigrationRenameAsAccount($user, $home, $target, $source);
        pmssUserWebRootMigrationLog($user, $logger, $restored
            ? 'Verification failed; restored '.$sourceRelative
            : 'Verification failed; durable copy preserved at '.$targetRelative);
        return;
    }

    if (pmssPathExistsOrLink($source) || !pmssUserWebRootMigrationRun($user, $home, [$source],
        'ln -sT -- '.escapeshellarg($linkTarget).' '.escapeshellarg($source))) {
        $restored = !pmssPathExistsOrLink($source)
            && pmssUserWebRootMigrationRenameAsAccount($user, $home, $target, $source);
        pmssUserWebRootMigrationLog($user, $logger, $restored
            ? 'Unable to link '.$sourceRelative.'; restored original path'
            : 'Unable to link '.$sourceRelative.'; durable copy preserved at '.$targetRelative);
        return;
    }

    pmssUserWebRootMigrationLog($user, $logger, 'Migrated '.$sourceRelative.' to durable storage');
}

/** Migrate the customer-owned web paths whose durable targets are ADR-defined. */
function pmssUserMigrateWebRootState(array $ctx, ?callable $logger = null): void
{
    [$user, $home] = pmssUserContextIdentity($ctx);
    if ($user === '' || $home === '') {
        return;
    }
    $log = $logger ?: function (string $message): void { logMessage($message); };

    foreach ([
        ['www/public', '.local/share/pmss/public', 'public'],
        ['www/rutorrent/share', '.local/share/pmss/rutorrent/share', 'share'],
    ] as $entry) {
        pmssUserMigrateWebRootBasenameCollapsePath($user, $home, $entry[0], $entry[1], $entry[2], $log);
        pmssUserMigrateWebRootPath($user, $home, $entry[0], $entry[1], $log);
    }
}
