<?php
/** Recover basename-collapsed web content through one staging lifetime.
 * @license GPL-3.0-only
 */

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

    if (is_link($source) ? readlink($source) !== $linkTarget : file_exists($source)) {
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
    $message = null;
    try {
        if (!pmssUserWebRootMigrationCopyTree($user, $home, $misfile, $temporary)) {
            $message = 'Unable to copy misfiled '.$misfileRelative.' to durable storage';
            return;
        }

        if ($before !== pmssUserWebRootMigrationSnapshot($temporary)) {
            $message = 'Verification failed; removed incomplete copy for '.$misfileRelative;
            return;
        }
        if (($conflict = pmssUserWebRootMigrationDestinationConflict($target, $targetRelative, $sourceRelative, false)) !== null) {
            $message = $conflict;
            return;
        }
        if (file_exists($target)
            && (!pmssUserWebRootMigrationDirectoryIsEmpty($target)
                || !pmssUserWebRootMigrationRun($user, $home, [$target], 'rmdir -- '.escapeshellarg($target)))) {
            $message = 'Preserving destination conflict for '.$sourceRelative;
            return;
        }

        if (!pmssUserWebRootMigrationRenameAsAccount($user, $home, $temporary, $target)) {
            $message = 'Unable to copy misfiled '.$misfileRelative.' to durable storage';
            return;
        }
        // Publication transfers the copy; later logger faults must leave it alone.
        $temporary = null;

        $message = 'Copied misfiled '.$misfileRelative.' to durable storage for '.$sourceRelative;
    } finally {
        // Every refusal and throwable shares cleanup; a cleanup fault suppresses logging.
        if ($temporary !== null) pmssUserWebRootMigrationRemoveCopy($user, $home, $temporary);
        if ($message !== null) pmssUserWebRootMigrationLog($user, $logger, $message);
    }
}
