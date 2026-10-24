<?php
/**
 * Skeleton copy and merge; preserve customer entries and account authority.
 *
 * @license GPL-3.0-only
 */

/** Copy one skeleton entry for a full restore or merge it without replacement. */
function pmssUserWebRootReconcileCopyEntry(
    string $source,
    string $target,
    string $home,
    string $relative = '',
    ?array &$stats = null,
    ?string $user = null,
    ?callable $logger = null,
    bool $merge = false
): bool
{
    if ($user === null) return false;
    if ($merge && (is_link($target) || (file_exists($target) && !is_dir($target) && !is_file($target)))) {
        pmssUserWebRootMigrationLog($user, $logger, 'Refusing conflicting web-root path: '.$relative);
        return true;
    }
    if ($merge && !is_dir($target) && file_exists($target)) {
        $conflict = is_link($source) || is_dir($source) || !is_file($source);
        if (!$conflict) {
            $sourceHash = @hash_file('sha256', $source);
            $targetHash = @hash_file('sha256', $target);
            $conflict = !is_string($sourceHash) || !is_string($targetHash) || $sourceHash !== $targetHash;
        }
        pmssUserWebRootMigrationLog($user, $logger, ($conflict
            ? 'Preserving conflicting web-root path: '
            : 'Preserving existing web-root path: ').$relative);
        return true;
    }
    if ($merge && !is_dir($target) && !pmssPathSegmentsAreSafe(dirname($target), false, false)) {
        pmssUserWebRootMigrationLog($user, $logger, 'Refusing unsafe web-root parent: '.dirname($target));
        return true;
    }

    if (is_link($source)) {
        $linkTarget = @readlink($source);
        $installed = is_string($linkTarget)
            && pmssUserWebRootReconcileLinkTargetIsSafe($target, $linkTarget, $home)
            && ($merge || (!file_exists($target) && !is_link($target)))
            && pmssUserWebRootMigrationRun($user, $home, [$target],
                'ln -sT -- '.escapeshellarg($linkTarget).' '.escapeshellarg($target));
        if ($merge && !$installed) {
            pmssUserWebRootMigrationLog($user, $logger, 'Refusing unsafe skeleton symlink: '.$relative);
        }
        return $merge || $installed;
    }

    $stat = @lstat($source);
    if (!is_array($stat)) {
        return $merge;
    }
    if (is_dir($source)) {
        $entries = pmssDirectoryEntriesRead($source);
        if ($entries === false) {
            return false;
        }
        $created = !is_dir($target);
        $mode = $stat['mode'] & 07777;
        $createdSuccessfully = !$created || pmssUserWebRootMigrationRun($user, $home, [$target],
            'mkdir -m 0700 -- '.escapeshellarg($target));
        if ($created && !$createdSuccessfully) {
            return $merge;
        }
        foreach ($entries as $child) {
            $childRelative = $relative === '' ? $child : $relative.'/'.$child;
            $baselineShare = !$merge && strpos($childRelative, 'rutorrent/share') === 0
                && !pmssPathExistsOrLink(rtrim($home, '/').'/.local/share/pmss/rutorrent/share');
            if (pmssUserWebRootReconcileMergeExcluded($childRelative) && ($merge || !$baselineShare)) {
                continue;
            }
            if (!pmssUserWebRootReconcileCopyEntry($source.'/'.$child, $target.'/'.$child, $home, $childRelative, $stats, $user, $logger, $merge)) {
                return false;
            }
        }
        if ($merge && $created) {
            pmssUserWebRootMigrationRun($user, $home, [$target],
                'chmod '.escapeshellarg(sprintf('%04o', $mode)).' -- '.escapeshellarg($target));
        }
        if (!$merge && !pmssUserWebRootMigrationRun($user, $home, [$target],
            'chmod '.sprintf('%04o', $mode).' -- '.escapeshellarg($target))) return false;
        return true;
    }
    if (!is_file($source) || pmssPathExistsOrLink($target)) {
        return $merge;
    }

    $installed = pmssUserWebRootReconcileInstallFile($source, $target, $stat['mode'] & 07777, $merge, $stats, $user, $home);
    return $merge || $installed;
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
