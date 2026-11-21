<?php
/** Misfiled web-root copy verification and publication. @license GPL-3.0-only */

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
    $copied = false;
    $message = 'Unable to copy misfiled '.$misfileRelative.' to durable storage';
    try {
        if (pmssUserWebRootMigrationCopyTree($user, $home, $misfile, $temporary)) {
            $after = pmssUserWebRootMigrationSnapshot($temporary);
            if ($after === null || $before !== $after) {
                $message = 'Verification failed; removed incomplete copy for '.$misfileRelative;
            } elseif (($conflict = pmssUserWebRootMigrationDestinationConflict($target, $targetRelative, $sourceRelative, false)) !== null) {
                $message = $conflict;
            } elseif (file_exists($target)
                && (!pmssUserWebRootMigrationDirectoryIsEmpty($target)
                    || !pmssUserWebRootMigrationRun($user, $home, [$target], 'rmdir -- '.escapeshellarg($target)))) {
                $message = 'Preserving destination conflict for '.$sourceRelative;
            } else {
                // Only verified publication transfers staging ownership.
                $copied = pmssUserWebRootMigrationRenameAsAccount($user, $home, $temporary, $target);
            }
        }
    } finally {
        // Ordinary refusals and throwables share cleanup, before any result logging.
        if (!$copied) pmssUserWebRootMigrationRemoveCopy($user, $home, $temporary);
    }
    pmssUserWebRootMigrationLog($user, $logger, $copied
        ? 'Copied misfiled '.$misfileRelative.' to durable storage for '.$sourceRelative : $message);
}
