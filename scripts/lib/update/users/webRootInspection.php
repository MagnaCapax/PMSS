<?php
/**
 * Read-only web-root inspection: traversal, snapshots, link safety, and ownership.
 *
 * @license GPL-3.0-only
 */

/**
 * Visit entries in directory order, stopping at the first rejected entry or scan.
 * Inspectors return true for directories, false for leaves, and null for rejection.
 * The inspector chooses descent before scanning; symlink policy stays with each caller.
 */
function pmssUserWebRootTreeInspect(string $path, string $relative, callable $inspect): bool
{
    $directory = $inspect($path, $relative);
    if ($directory === null) return false;
    if (!$directory) return true;
    $children = pmssDirectoryEntriesRead($path);
    if ($children === false) return false;
    foreach ($children as $child) {
        $childRelative = $relative === '' ? $child : $relative.'/'.$child;
        if (!pmssUserWebRootTreeInspect($path.'/'.$child, $childRelative, $inspect)) return false;
    }
    return true;
}

/**
 * Collect file metadata with content or inode identity without traversing symlinks.
 *
 * A symlink anywhere in a moved tree is refused because relocating it could
 * change the meaning of a relative target even though rename() itself is safe.
 */
function pmssUserWebRootMigrationSnapshotWalk(
    string $path,
    string $relative,
    array &$snapshot,
    bool $identity
): bool
{
    return pmssUserWebRootTreeInspect($path, $relative, static function (string $path, string $relative) use (&$snapshot, $identity): ?bool {
        if (is_link($path) || !is_array($stat = @lstat($path))) {
            return null;
        }

        $entry = [
            'type' => is_dir($path) ? 'dir' : (is_file($path) ? 'file' : 'other'),
            'mode' => $stat['mode'] & 07777,
            'uid'  => (int) ($stat['uid'] ?? -1),
            'gid'  => (int) ($stat['gid'] ?? -1),
        ];
        if ($entry['type'] === 'other') {
            return null;
        }
        // Identity snapshots compare inode metadata; content snapshots hash only files.
        $fields = $identity ? ($entry['type'] === 'file' ? ['dev', 'ino', 'size', 'mtime'] : ['dev', 'ino']) : [];
        foreach ($fields as $field) {
            if (!isset($stat[$field])) return null;
            $entry[$field] = (int) $stat[$field];
        }
        if (!$identity && $entry['type'] === 'file') {
            $entry['sha256'] = @hash_file('sha256', $path);
            if (!is_string($entry['sha256'])) {
                return null;
            }
        }
        $snapshot[$relative] = $entry;
        return $entry['type'] === 'dir';
    });
}

/** Return a content snapshot by default, or an inode-identity snapshot for rename verification. */
function pmssUserWebRootMigrationSnapshot(string $path, bool $identity = false): ?array
{
    if (!is_dir($path) || is_link($path)) {
        return null;
    }

    $snapshot = [];
    if (!pmssUserWebRootMigrationSnapshotWalk($path, '', $snapshot, $identity)) {
        return null;
    }
    ksort($snapshot);
    return $snapshot;
}

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
    return pmssUserWebRootTreeInspect($path, $relative, static function (string $path, string $entryRelative) use ($home, $placed, $relative): ?bool {
        // The caller's root has already been selected; only descendant names need validation.
        if ($entryRelative !== $relative && !pmssPathRelativeStringIsSafe($entryRelative)) return null;
        if (is_link($path)) {
            $target = (string) @readlink($path);
            $safe = $placed
                ? pmssUserWebRootReconcileLinkTargetIsSafe($path, $target, $home)
                : pmssUserWebRootReconcileRelativeLinkIsSafe($target);
            return $safe ? false : null;
        }
        if (!is_dir($path) && !is_file($path)) return null;
        return !is_file($path);
    });
}

/** Confirm an account-created tree has the expected owner without changing it. */
function pmssUserWebRootReconcileApplyOwnership(string $path, string $user): bool
{
    return pmssUserWebRootTreeInspect($path, '', static function (string $path) use ($user): ?bool {
        if (is_link($path)) return false;
        $account = function_exists('posix_getpwnam') ? @posix_getpwnam($user) : false;
        $stat = @lstat($path);
        $currentUid = function_exists('posix_geteuid') ? @posix_geteuid() : -1;
        $expectedUid = is_array($account) ? (int) $account['uid'] : ($currentUid > 0 ? $currentUid : -1);
        if (!is_array($stat) || (int) $stat['uid'] !== $expectedUid) return null;
        return is_dir($path);
    });
}
