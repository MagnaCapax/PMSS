<?php
/**
 * Shared per-user web-root recovery and convergence.
 *
 * Full recovery replaces only an empty root; partial recovery adds missing
 * managed skeleton entries and never overwrites customer-owned paths.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/webRoot.php';
require_once dirname(__DIR__, 2).'/runtime/locks.php';
require_once dirname(__DIR__, 2).'/rutorrent/config.php';

/** Reject unsafe skeleton symlinks before copying them into a user home. */
function pmssUserWebRootReconcileRelativeLinkIsSafe(string $target): bool
{
    if ($target === '' || $target[0] === '/' || strpos($target, "\0") !== false) {
        return false;
    }
    foreach (explode('/', $target) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            continue;
        }
        if (!pmssPathRelativeStringIsSafe($segment)) {
            return false;
        }
    }

    return true;
}

/** Check a relative symlink target after it has been placed under the home. */
function pmssUserWebRootReconcileLinkTargetIsSafe(string $linkPath, string $target, string $home): bool
{
    if (!pmssUserWebRootReconcileRelativeLinkIsSafe($target)) {
        return false;
    }

    $home = rtrim($home, '/').'/';
    if (strpos($linkPath, $home) !== 0) {
        return false;
    }

    $parentRelative = trim(substr(dirname($linkPath), strlen($home)), '/');
    $segments = $parentRelative === '' ? [] : explode('/', $parentRelative);
    foreach (explode('/', $target) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            if ($segments === []) {
                return false;
            }
            array_pop($segments);
            continue;
        }
        if (!pmssPathRelativeStringIsSafe($segment)) {
            return false;
        }
        $segments[] = $segment;
    }

    return true;
}

/** Validate a copied tree without following any symlink. */
function pmssUserWebRootReconcileTreeIsSafe(string $path, string $home, bool $placed = true, string $relative = ''): bool
{
    if (is_link($path)) {
        $target = (string) @readlink($path);
        return $placed
            ? pmssUserWebRootReconcileLinkTargetIsSafe($path, $target, $home)
            : pmssUserWebRootReconcileRelativeLinkIsSafe($target);
    }
    if (!is_dir($path) && !is_file($path)) {
        return false;
    }
    if (is_file($path)) {
        return true;
    }

    foreach (@scandir($path) ?: [] as $child) {
        if ($child === '.' || $child === '..') {
            continue;
        }
        $childRelative = $relative === '' ? $child : $relative.'/'.$child;
        if (!pmssPathRelativeStringIsSafe($childRelative)
            || !pmssUserWebRootReconcileTreeIsSafe($path.'/'.$child, $home, $placed, $childRelative)) {
            return false;
        }
    }

    return true;
}

/** Apply tenant ownership without following links; copy already applied modes. */
function pmssUserWebRootReconcileApplyOwnership(string $path, string $user): bool
{
    if (is_link($path)) {
        return true;
    }
    if (!is_array(@lstat($path))) {
        return false;
    }
    if (function_exists('posix_geteuid') && @posix_geteuid() === 0) {
        if (!@chown($path, $user) || !@chgrp($path, $user)) {
            return false;
        }
    }
    if (!is_dir($path)) {
        return true;
    }

    foreach (@scandir($path) ?: [] as $child) {
        if ($child !== '.' && $child !== '..'
        && !pmssUserWebRootReconcileApplyOwnership($path.'/'.$child, $user)) {
            return false;
        }
    }

    return true;
}

/** Install a copied file without replacing an entry created concurrently. */
function pmssUserWebRootReconcileInstallFile(string $source, string $target, int $mode, bool $noReplace): bool
{
    $temporary = @tempnam(dirname($target), '.pmss-web-root-');
    if ($temporary === false || is_link($temporary) || !@copy($source, $temporary)) {
        if (is_string($temporary)) {
            @unlink($temporary);
        }
        return false;
    }
    @chmod($temporary, $mode);
    $installed = $noReplace ? @link($temporary, $target) : @rename($temporary, $target);
    @unlink($temporary);
    return $installed;
}

/** Copy one validated skeleton entry into an empty staging tree. */
function pmssUserWebRootReconcileCopyEntry(
    string $source,
    string $target,
    string $home,
    string $relative = ''
): bool
{
    if (is_link($source)) {
        $linkTarget = @readlink($source);
        return is_string($linkTarget)
            && pmssUserWebRootReconcileLinkTargetIsSafe($target, $linkTarget, $home)
            && !file_exists($target)
            && !is_link($target)
            && @symlink($linkTarget, $target);
    }

    $stat = @lstat($source);
    if (!is_array($stat)) {
        return false;
    }
    if (is_dir($source)) {
        if (!is_dir($target) && !@mkdir($target, $stat['mode'] & 07777)) {
            return false;
        }
        @chmod($target, $stat['mode'] & 07777);
        foreach (@scandir($source) ?: [] as $child) {
            if ($child === '.' || $child === '..') {
                continue;
            }
            $childRelative = $relative === '' ? $child : $relative.'/'.$child;
            $baselineShare = strpos($childRelative, 'rutorrent/share') === 0
                && !file_exists(rtrim($home, '/').'/.local/share/pmss/rutorrent/share')
                && !is_link(rtrim($home, '/').'/.local/share/pmss/rutorrent/share');
            if (pmssUserWebRootReconcileMergeExcluded($childRelative) && !$baselineShare) {
                continue;
            }
            if (!pmssUserWebRootReconcileCopyEntry($source.'/'.$child, $target.'/'.$child, $home, $childRelative)) {
                return false;
            }
        }
        return true;
    }
    if (!is_file($source) || file_exists($target) || is_link($target)) {
        return false;
    }

    return pmssUserWebRootReconcileInstallFile($source, $target, $stat['mode'] & 07777, false);
}

/** Remove only a staging tree created by this reconciler. */
function pmssUserWebRootReconcileRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (@scandir($path) ?: [] as $child) {
        if ($child !== '.' && $child !== '..') {
            pmssUserWebRootReconcileRemoveTree($path.'/'.$child);
        }
    }
    @rmdir($path);
}

/** Copy one missing managed entry, preserving every existing path. */
function pmssUserWebRootReconcileMergeEntry(
    string $source,
    string $target,
    string $home,
    string $user,
    ?callable $logger,
    string $relative
): void {
    if (is_link($target) || (file_exists($target) && !is_dir($target) && !is_file($target))) {
        pmssUserWebRootMigrationLog($user, $logger, 'Refusing conflicting web-root path: '.$relative);
        return;
    }
    if (is_dir($target)) {
        if (is_link($target)) {
            pmssUserWebRootMigrationLog($user, $logger, 'Refusing symlinked web-root directory: '.$relative);
            return;
        }
        foreach (@scandir($source) ?: [] as $child) {
            if ($child === '.' || $child === '..') {
                continue;
            }
            $childRelative = $relative === '' ? $child : $relative.'/'.$child;
            if (!pmssUserWebRootReconcileMergeExcluded($childRelative)) {
                pmssUserWebRootReconcileMergeEntry($source.'/'.$child, $target.'/'.$child, $home, $user, $logger, $childRelative);
            }
        }
        return;
    }
    if (file_exists($target)) {
        pmssUserWebRootMigrationLog($user, $logger, 'Preserving existing web-root path: '.$relative);
        return;
    }
    if (!pmssPathSegmentsAreSafe(dirname($target), false, false)) {
        pmssUserWebRootMigrationLog($user, $logger, 'Refusing unsafe web-root parent: '.dirname($target));
        return;
    }

    $stat = @lstat($source);
    if (!is_array($stat)) {
        return;
    }
    if (is_link($source)) {
        $linkTarget = @readlink($source);
        if (is_string($linkTarget)
            && pmssUserWebRootReconcileLinkTargetIsSafe($target, $linkTarget, $home)
            && @symlink($linkTarget, $target)) {
            return;
        }
        pmssUserWebRootMigrationLog($user, $logger, 'Refusing unsafe skeleton symlink: '.$relative);
        return;
    }
    if (is_dir($source)) {
        if (@mkdir($target, $stat['mode'] & 07777)) {
            @chmod($target, $stat['mode'] & 07777);
            if (function_exists('posix_geteuid') && @posix_geteuid() === 0) {
                @chown($target, $user);
                @chgrp($target, $user);
            }
            foreach (@scandir($source) ?: [] as $child) {
                if ($child !== '.' && $child !== '..') {
                    $childRelative = $relative.'/'.$child;
                    if (!pmssUserWebRootReconcileMergeExcluded($childRelative)) {
                        pmssUserWebRootReconcileMergeEntry($source.'/'.$child, $target.'/'.$child, $home, $user, $logger, $childRelative);
                    }
                }
            }
        }
        return;
    }
    if (!is_file($source)) {
        return;
    }

    pmssUserWebRootReconcileInstallFile($source, $target, $stat['mode'] & 07777, true);
}

/** Customer-owned paths are restored by the migration/link helper, never skel-merged. */
function pmssUserWebRootReconcileMergeExcluded(string $relative): bool
{
    foreach (['data', 'watch', 'public', 'rutorrent/share', 'rutorrent/conf/users'] as $excluded) {
        if ($relative === $excluded || strpos($relative, $excluded.'/') === 0) {
            return true;
        }
    }

    return false;
}

/** Restore a complete tree only when the current root contains no entries. */
function pmssUserWebRootReconcileFull(string $www, string $skeleton, string $home, string $user, ?callable $logger): bool
{
    try {
        $stage = $home.'/.pmss-web-root-reconcile-'.bin2hex(random_bytes(6));
    } catch (\Throwable $exception) {
        pmssUserWebRootMigrationLog($user, $logger, 'Unable to create web-root staging name');
        return false;
    }
    if (!pmssPathAbsoluteStringIsSafe($stage, ['allowRoot' => false]) || !@mkdir($stage, 0700)) {
        return false;
    }

    $success = pmssUserWebRootReconcileTreeIsSafe($skeleton, $home, false)
        && pmssUserWebRootReconcileCopyEntry($skeleton, $stage, $home)
        && pmssUserWebRootReconcileTreeIsSafe($stage, $home)
        && pmssUserWebRootReconcileApplyOwnership($stage, $user);
    if ($success) {
        $entries = @scandir($www);
        $currentRootIsSafe = !is_link($www) && (!$entries || count(array_diff($entries, ['.', '..'])) === 0);
        $success = $currentRootIsSafe && @rename($stage, $www);
    }
    if (!$success) {
        pmssUserWebRootReconcileRemoveTree($stage);
        pmssUserWebRootMigrationLog($user, $logger, 'Full web-root restore refused or failed');
        return false;
    }

    pmssUserWebRootMigrationLog($user, $logger, 'Restored complete web root from skeleton');
    return true;
}

/** Reconcile one user's web root; callers may safely retry after a lock skip. */
function pmssUserReconcileWebRoot(array $ctx, ?callable $logger = null): bool
{
    $user = (string) ($ctx['user'] ?? '');
    $home = rtrim((string) ($ctx['home'] ?? ''), '/');
    $homeRoot = pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home');
    if ($user === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $user)
        || $home === '' || !pmssPathTargetIsSafe($home, true) || is_link($home)
        || !pmssPathWithinResolvedRoot($home, $homeRoot)) {
        return false;
    }

    $lockDir = pmssResolvePathFromEnv('PMSS_USER_WEB_ROOT_LOCK_DIR', is_dir('/run/lock') ? '/run/lock' : '/tmp');
    $lockPath = pmssPathAbsoluteStringIsSafe($lockDir, ['allowRoot' => false])
        && is_dir($lockDir) && !is_link($lockDir)
        ? $lockDir.'/pmss-user-web-root-'.$user.'.lock' : '';
    if ($lockPath === '' || ($lock = pmssLockFileAcquire($lockPath, true)) === false) {
        pmssUserWebRootMigrationLog($user, $logger, 'Web-root reconciliation skipped because the per-user lock is busy or unsafe');
        return false;
    }

    try {
        $www = $home.'/www';
        $skeleton = pmssSkeletonBase().'/www';
        if (!pmssPathSegmentsAreSafe($www, false, false)
            || !is_dir($skeleton)
            || is_link($skeleton)
            || !pmssPathTargetIsSafe($skeleton, true)) {
            return false;
        }
        if (is_link($www) || (file_exists($www) && !is_dir($www))) {
            pmssUserWebRootMigrationLog($user, $logger, 'Refusing unsafe or conflicting www path');
            return false;
        }

        $entries = is_dir($www) ? (@scandir($www) ?: []) : [];
        $fullRestore = !is_dir($www) || count(array_diff($entries, ['.', '..'])) === 0;
        $result = $fullRestore ? pmssUserWebRootReconcileFull($www, $skeleton, $home, $user, $logger) : true;
        if (!$fullRestore) {
            foreach (@scandir($skeleton) ?: [] as $child) {
                if ($child !== '.' && $child !== '..' && !pmssUserWebRootReconcileMergeExcluded($child)) {
                    pmssUserWebRootReconcileMergeEntry($skeleton.'/'.$child, $www.'/'.$child, $home, $user, $logger, $child);
                }
            }
        }
        if (!$result) {
            return false;
        }

        pmssUserEnsureWebRootSymlinks($ctx);
        pmssUserMigrateWebRootState($ctx, $logger);
        if ($fullRestore) {
            $configRoot = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');
            if (function_exists('updateRutorrentConfig')
                && is_file($configRoot.'/template.rutorrent.config')
                && is_file($configRoot.'/template.rutorrent.access')) {
                updateRutorrentConfig($user, 1);
            } else {
                pmssUserWebRootMigrationLog($user, $logger, 'ruTorrent templates unavailable; config regeneration skipped');
            }
        }
        return true;
    } finally {
        pmssLockHandleRelease($lock);
    }
}
