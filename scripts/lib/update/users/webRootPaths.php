<?php
/**
 * Web-root path selection, parent preparation, and destination conflict policy.
 *
 * @license GPL-3.0-only
 */

/** Validate an existing or future migration parent without following symlinks. */
function pmssUserWebRootMigrationParentSafe(string $home, string $path): bool
{
    $home = rtrim($home, '/');
    $path = rtrim($path, '/');
    return pmssPathAbsoluteStringIsSafe($home, ['allowRoot' => false])
        && pmssPathAbsoluteStringIsSafe($path, ['allowRoot' => false])
        && ($path === $home || strpos($path, $home.'/') === 0)
        && pmssPathSegmentsAreSafe($path, false, false);
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
    if (pmssPathExistsOrLink($path)) {
        pmssUserWebRootMigrationLog($user, $logger, 'Refusing conflicting web-root migration parent: '.$path);
        return false;
    }

    if (!pmssAccountDirectoryEnsure($user, $home, $path, 0755, 0755, $logger)) {
        pmssUserWebRootMigrationLog($user, $logger, 'Unable to create web-root migration parent: '.$path);
        return false;
    }

    return is_dir($path) && !is_link($path);
}

/** Return true only for an existing, readable directory with no children. */
function pmssUserWebRootMigrationDirectoryIsEmpty(string $path): bool
{
    $entries = pmssDirectoryEntriesRead($path);
    return is_array($entries) && empty($entries);
}

/** Describe conflicting destination types, optionally requiring an empty existing directory. */
function pmssUserWebRootMigrationDestinationConflict(
    string $target, string $targetRelative, string $sourceRelative, bool $requireEmpty
): ?string {
    if (is_link($target)) return 'Preserving symlink destination conflict at '.$targetRelative;
    if (file_exists($target) && !is_dir($target)) return 'Preserving non-directory destination conflict at '.$targetRelative;
    if ($requireEmpty && is_dir($target) && !pmssUserWebRootMigrationDirectoryIsEmpty($target)) {
        return 'Preserving destination conflict for '.$sourceRelative;
    }
    return null;
}
