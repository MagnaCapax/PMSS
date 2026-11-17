<?php
/** Move customer web state to durable storage with verified rollback.
 * @license GPL-3.0-only
 */

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
    $linkTarget = pmssUserWebRootMigrationRelativeTarget($home, $source, $target);
    if ($linkTarget === null
        || !pmssUserWebRootMigrationParentSafe($home, dirname($source))
        || !pmssUserWebRootMigrationParentSafe($home, dirname($target))) {
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
        if (!pmssUserWebRootMigrationPrepareParent($user, $home, dirname($source), $logger)
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
    if (!pmssUserWebRootMigrationPrepareParent($user, $home, dirname($target), $logger)
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
