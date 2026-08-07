<?php
/**
 * Customer-owned web-root migration helpers.
 *
 * Move mutable web content below the durable home paths before linking it back
 * into www. The move is deliberately conservative: conflicts and symlinks are
 * left untouched so a later reconciliation pass cannot lose customer data.
 *
 * @license GPL-3.0-only
 */

require_once dirname(__DIR__, 2).'/pathSafety.php';
require_once dirname(__DIR__, 2).'/user/directories.php';

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

/** Validate an existing or future migration parent without following symlinks. */
function pmssUserWebRootMigrationParentSafe(string $home, string $path): bool
{
    $home = rtrim($home, '/');
    $path = rtrim($path, '/');
    return pmssPathAbsoluteStringIsSafe($home, ['allowRoot' => false])
        && pmssPathAbsoluteStringIsSafe($path, ['allowRoot' => false])
        && strpos($path, $home.'/') === 0
        && pmssPathSegmentsAreSafe($path, false, false);
}

/**
 * Collect file metadata and hashes without traversing symlinks.
 *
 * A symlink anywhere in a moved tree is refused because relocating it could
 * change the meaning of a relative target even though rename() itself is safe.
 */
function pmssUserWebRootMigrationSnapshotWalk(string $path, string $relative, array &$snapshot): bool
{
    if (is_link($path) || !is_array($stat = @lstat($path))) {
        return false;
    }

    $entry = [
        'type' => is_dir($path) ? 'dir' : (is_file($path) ? 'file' : 'other'),
        'mode' => $stat['mode'] & 07777,
        'uid'  => (int) ($stat['uid'] ?? -1),
        'gid'  => (int) ($stat['gid'] ?? -1),
    ];
    if ($entry['type'] === 'other') {
        return false;
    }
    if ($entry['type'] === 'file') {
        $entry['sha256'] = @hash_file('sha256', $path);
        if (!is_string($entry['sha256'])) {
            return false;
        }
    }
    $snapshot[$relative] = $entry;

    if ($entry['type'] !== 'dir') {
        return true;
    }

    $children = @scandir($path);
    if (!is_array($children)) {
        return false;
    }
    foreach ($children as $child) {
        if ($child === '.' || $child === '..') {
            continue;
        }
        $childRelative = $relative === '' ? $child : $relative.'/'.$child;
        if (!pmssUserWebRootMigrationSnapshotWalk($path.'/'.$child, $childRelative, $snapshot)) {
            return false;
        }
    }

    return true;
}

/** Return a verified snapshot, or null when the tree is unsafe to move. */
function pmssUserWebRootMigrationSnapshot(string $path): ?array
{
    if (!is_dir($path) || is_link($path)) {
        return null;
    }

    $snapshot = [];
    if (!pmssUserWebRootMigrationSnapshotWalk($path, '', $snapshot)) {
        return null;
    }
    ksort($snapshot);
    return $snapshot;
}

/** Compute the relative symlink target from an in-www path to its home path. */
function pmssUserWebRootMigrationRelativeTarget(string $home, string $source, string $target): ?string
{
    $home = rtrim($home, '/').'/';
    if (strpos($source, $home) !== 0 || strpos($target, $home) !== 0) {
        return null;
    }

    $sourceParent = trim(substr(dirname($source), strlen($home)), '/');
    $targetRelative = trim(substr($target, strlen($home)), '/');
    if ($targetRelative === '') {
        return null;
    }

    $up = $sourceParent === '' ? '' : str_repeat('../', count(explode('/', $sourceParent)));
    return $up.$targetRelative;
}

/** Create missing parents while preserving existing parent metadata. */
function pmssUserWebRootMigrationPrepareParent(string $user, string $home, string $path, callable $logger): bool
{
    if (!pmssUserWebRootMigrationParentSafe($home, $path)) {
        pmssUserWebRootMigrationLog($user, $logger, 'Refusing unsafe web-root migration parent: '.$path);
        return false;
    }
    if (is_dir($path) && !is_link($path)) {
        return true;
    }
    if (is_link($path) || file_exists($path)) {
        pmssUserWebRootMigrationLog($user, $logger, 'Refusing conflicting web-root migration parent: '.$path);
        return false;
    }

    if (!pmssEnsureDir($path, 0755, $user, $user, $logger, 0755)) {
        pmssUserWebRootMigrationLog($user, $logger, 'Unable to create web-root migration parent: '.$path);
        return false;
    }

    return is_dir($path) && !is_link($path);
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
    if (is_link($target)) {
        pmssUserWebRootMigrationLog($user, $logger, 'Preserving symlink destination conflict at '.$targetRelative);
        return;
    }
    $targetExists = file_exists($target);
    if ($targetExists && !is_dir($target)) {
        pmssUserWebRootMigrationLog($user, $logger, 'Preserving non-directory destination conflict at '.$targetRelative);
        return;
    }
    if (!$sourceExists && !$targetExists) {
        return;
    }
    if ($sourceExists && $targetExists) {
        pmssUserWebRootMigrationLog($user, $logger, 'Preserving destination conflict for '.$sourceRelative);
        return;
    }

    if (!$sourceExists) {
        if (!pmssUserWebRootMigrationPrepareParent($user, $home, $sourceParent, $logger)
            || is_link($source) || file_exists($source)
            || !@symlink($linkTarget, $source)) {
            pmssUserWebRootMigrationLog($user, $logger, 'Unable to restore symlink for '.$sourceRelative);
            return;
        }
        pmssUserWebRootMigrationLog($user, $logger, 'Restored symlink for '.$sourceRelative);
        return;
    }

    $before = pmssUserWebRootMigrationSnapshot($source);
    if ($before === null) {
        pmssUserWebRootMigrationLog($user, $logger, 'Refusing unsafe symlink or unreadable tree at '.$sourceRelative);
        return;
    }
    if (!pmssUserWebRootMigrationPrepareParent($user, $home, $targetParent, $logger)
        || is_link($target) || file_exists($target)
        || !@rename($source, $target)) {
        pmssUserWebRootMigrationLog($user, $logger, 'Unable to move '.$sourceRelative.' to durable storage');
        return;
    }

    $after = pmssUserWebRootMigrationSnapshot($target);
    if ($after === null || $before !== $after) {
        $restored = !file_exists($source) && !is_link($source) && @rename($target, $source);
        pmssUserWebRootMigrationLog($user, $logger, $restored
            ? 'Verification failed; restored '.$sourceRelative
            : 'Verification failed; durable copy preserved at '.$targetRelative);
        return;
    }

    if (is_link($source) || file_exists($source) || !@symlink($linkTarget, $source)) {
        $restored = !file_exists($source) && !is_link($source) && @rename($target, $source);
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
    $user = (string) ($ctx['user'] ?? '');
    $home = rtrim((string) ($ctx['home'] ?? ''), '/');
    if ($user === '' || $home === '') {
        return;
    }
    $log = $logger ?: function (string $message): void { logMessage($message); };

    foreach ([
        ['www/public', '.local/share/pmss/public'],
        ['www/rutorrent/share', '.local/share/pmss/rutorrent/share'],
    ] as $entry) {
        pmssUserMigrateWebRootPath($user, $home, $entry[0], $entry[1], $log);
    }
}
