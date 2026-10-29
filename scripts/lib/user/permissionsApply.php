<?php
/**
 * Mode and owner repairs used by userPermissions.php.
 *
 * The account owns its home and can rename or replace any entry in it while a
 * repair runs. Each repair therefore acts on the object it checked:
 * - modes on account-owned entries change as the account, which can change
 *   only its own files;
 * - modes on root-owned files change through the opened inode, after the
 *   handle is confirmed to be a single-link regular file inside the home;
 * - the group of a root-owned file whose reader trusts a root owner changes
 *   the same way, and its owner never changes, so no account file is adopted;
 * - owners change from a find that starts at the home and descends only
 *   through directories it opens itself; -execdir runs chown -h inside the
 *   directory find holds, so no path is resolved again by name.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../pathSafety.php';
require_once __DIR__.'/../lighttpd/accountPath.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';
require_once __DIR__.'/log.php';
require_once __DIR__.'/permissionsCommands.php';

/** Run one repair command as the account, keeping a failure for the final summary. */
function pmssUserPermissionsAccountRun(string $user, string $home, array $paths, string $command): void
{
    if (!pmssAccountPathRun($user, $home, $paths, $command, null, 0, $timedOut, $failure)) {
        pmssUserPermissionsFailed($command.' (as '.$user.') '.$failure);
    }
}

/**
 * Set the mode of one configured path or glob.
 * A root-owned single-link file changes through its opened inode; every other
 * entry changes as the account, which skips entries owned by anyone else and
 * does not descend into directories it does not own.
 */
function pmssUserPermissionsModeApply(string $user, string $home, int $uid, string $path, int $mode, bool $recursive = false): void
{
    $octal = sprintf('%04o', $mode);
    foreach (pmssPathShellTargetPaths($path) as $target) {
        clearstatcache(true, $target);
        $entry = @lstat($target);
        if (!$recursive && pmssUserFileStatIsSingleRegular($entry) && $entry['uid'] === 0) {
            pmssUserPermissionsOpenedModeSet($user, $home, $target, $mode, 0);
            continue;
        }
        $command = $recursive
            ? sprintf('find %s -type d -not -uid %d -prune -o -uid %d -not -type l \( ! -type f -o -links 1 \) -not -perm %s -exec chmod %s {} +', escapeshellarg($target), $uid, $uid, $octal, $octal)
            : sprintf('find %s -maxdepth 0 -uid %d -not -type l \( ! -type f -o -links 1 \) -exec chmod %s {} +', escapeshellarg($target), $uid, $octal);
        pmssUserPermissionsAccountRun($user, $home, [$target], $command);
    }
}

/**
 * Change the mode of one single-link regular file owned by $ownerUid through its
 * opened inode. Anything else at that name is logged and left as it is.
 */
function pmssUserPermissionsOpenedModeSet(string $user, string $home, string $path, int $mode, int $ownerUid): bool
{
    $handle = pmssUserFileOpenVerified($path, 'rbn');
    $opened = $handle === null ? null : pmssUserPermissionsHandleInHome($handle, $home, $ownerUid);
    try {
        if ($opened === null) {
            pmssUserLog($user, sprintf('userPermissions: %s is not a single-link file owned by uid %d inside the home; mode left as-is', basename($path), $ownerUid));
            return false;
        }
        if (@chmod($opened, $mode)) {
            return true;
        }
        pmssUserPermissionsFailed(sprintf('chmod %04o on opened %s', $mode, $path));
        return false;
    } finally {
        if ($handle !== null) {
            @fclose($handle);
        }
    }
}

/**
 * Set the group of one single-link regular file owned by $ownerUid through its
 * opened inode. The owner is never changed, so a file the account placed at
 * that name cannot be adopted. Anything else at that name is logged and left.
 */
function pmssUserPermissionsOpenedGroupSet(string $user, string $home, string $path, string $group, int $ownerUid): bool
{
    $handle = pmssUserFileOpenVerified($path, 'rbn');
    $opened = $handle === null ? null : pmssUserPermissionsHandleInHome($handle, $home, $ownerUid);
    try {
        if ($opened === null) {
            pmssUserLog($user, sprintf('userPermissions: %s is not a single-link file owned by uid %d inside the home; group left as-is', basename($path), $ownerUid));
            return false;
        }
        if (@chgrp($opened, $group)) {
            return true;
        }
        pmssUserPermissionsFailed(sprintf('chgrp %s on opened %s', $group, $path));
        return false;
    } finally {
        if ($handle !== null) {
            @fclose($handle);
        }
    }
}

/**
 * Return /proc/self/fd/N for an open single-link regular file owned by
 * $ownerUid whose current location is inside $home, else null. The location
 * comes from the descriptor, so a directory on the path that was replaced by a
 * link before the open shows up here as a location outside the home.
 */
function pmssUserPermissionsHandleInHome($handle, string $home, int $ownerUid): ?string
{
    $opened = pmssStreamHandleIsOpen($handle) ? @fstat($handle) : false;
    $root = @realpath($home);
    $procPath = pmssUserFileHandleProcPath($handle);
    if (!pmssUserFileStatIsSingleRegular($opened) || $opened['uid'] !== $ownerUid
        || !is_string($root) || $root === '/' || $procPath === null) {
        return null;
    }
    $location = @readlink($procPath);
    return is_string($location) && strpos($location, $root.'/') === 0 ? $procPath : null;
}

/**
 * Build a find that starts at $home and reaches $target only through the
 * directories on its path, pruning everything else. With $recursive the whole
 * tree under $target is visited. $tests and $action apply to the target (and
 * its tree); the action should be an -execdir form. Returns null for a target
 * outside the home or with empty, "." or ".." segments.
 */
function pmssUserPermissionsAnchoredFind(string $home, string $target, bool $recursive, string $tests, string $action): ?string
{
    $home = rtrim($home, '/');
    $target = rtrim($target, '/');
    if ($home === '' || $home[0] !== '/' || strpos($home, "\0") !== false || strpos($target, "\0") !== false) {
        return null;
    }
    if ($target === $home) {
        return sprintf('find %s -maxdepth 0 %s %s', escapeshellarg($home), $tests, $action);
    }
    if (strpos($target, $home.'/') !== 0) {
        return null;
    }
    foreach (explode('/', substr($target, strlen($home) + 1)) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return null;
        }
    }

    $pattern = static function (string $path, string $suffix = ''): string {
        return '-path '.escapeshellarg(addcslashes($path, '\\*?[]').$suffix);
    };
    $match = $recursive
        ? '\( '.$pattern($target).' -o '.$pattern($target, '/*').' \)'
        : $pattern($target).' -prune';
    $parents = [];
    for ($dir = dirname($target); $dir !== $home; $dir = dirname($dir)) {
        $parents[] = $pattern($dir);
    }

    $command = sprintf('find %s -mindepth 1 \( %s \( %s %s -o -true \) \)', escapeshellarg($home), $match, $tests, $action);
    if ($parents !== []) {
        $command .= ' -o \( '.implode(' -o ', $parents).' \)';
    }
    return $command.' -o -prune';
}

/** Set the owner of one entry (or tree) inside the home with chown -h run by -execdir. */
function pmssUserPermissionsOwnerApply(string $home, string $path, string $owner, string $predicate = '', bool $recursive = false): void
{
    $tests = trim('-not -type l \( ! -type f -o -links 1 \) '.$predicate);
    $action = '-execdir chown -h '.escapeshellarg($owner).' {} +';
    $command = pmssUserPermissionsAnchoredFind($home, $path, $recursive, $tests, $action);
    if ($command !== null) {
        pmssUserPermissionsRun($command);
    }
}
