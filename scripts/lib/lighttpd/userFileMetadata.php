<?php
/** Opened-inode and account metadata operations. @license GPL-3.0-only */

/**
 * Set owner and group on one managed entry. Real paths use lchown/lchgrp so a
 * planted link is never followed. An opened-inode path must be followed:
 * lchown on the procfs link would change only that link, not the file.
 */
function pmssUserFileOwnershipSet(string $path, $owner, $group): bool
{
    if (pmssUserFileOpenedPathIs($path)) {
        $ownerSet = @chown($path, $owner);
        return @chgrp($path, $group) && $ownerSet;
    }
    $ownerSet = @lchown($path, $owner);
    return @lchgrp($path, $group) && $ownerSet;
}

/**
 * Apply file mode plus root-only ownership metadata to a managed user path.
 * Account-home paths retain their account/root-stage routing (ADR 0121).
 * Other real paths must be single-link regular files; metadata changes use
 * the opened inode so later name changes cannot redirect chmod/chown.
 */
function pmssUserFileApplyMetadata(string $path, string $owner, int $mode, ?string $group = null): void
{
    if (pmssManagedAccountHomePath($path)) {
        $context = pmssRootStageContext($path);
        $entry = @lstat($path);
        $identity = pmssAccountHomeIdentity($path);
        if ($identity === null || !pmssUserFileStatIsSingleRegular($entry) || $mode < 0 || $mode > 0777) {
            error_log("Unable to update managed metadata: {$path}");
            return;
        }
        list($home, $user) = $identity;
        if ($owner === $user) {
            $account = @posix_getpwnam($user);
            if (!is_array($account) || $entry['uid'] !== $account['uid']) return;
            $target = escapeshellarg($path);
            $command = 'test ! -L '.$target.' && chmod '.sprintf('%o', $mode).' -- '.$target;
            if (!pmssAccountPathRun($user, $home, [$path], $command)) {
                error_log("Unable to update account metadata for {$user}: {$path}");
            }
            return;
        }
        $failure = '';
        if ($context !== null && $owner === 'root' && $entry['uid'] === 0 && ($entry['mode'] & 0170000) === 0100000) {
            $content = pmssReadRegularFileContents($path);
            if (is_string($content) && pmssRootStageFileWrite($path, $content, $group ?: 'root', $mode, false, $failure)) return;
        }
        error_log("Unable to update managed metadata: {$path}".($failure === '' ? '' : ' ('.$failure.')'));
        return;
    }
    if (pmssUserFileOpenedPathIs($path)) {
        @chmod($path, $mode);
        if (function_exists('posix_geteuid') && @posix_geteuid() === 0) {
            pmssUserFileOwnershipSet($path, $owner, ($group === null || $group === '') ? $owner : $group);
        }
        return;
    }

    $handle = pmssUserFileOpenVerified($path, 'rbn');
    if ($handle === null) {
        return;
    }
    try {
        $openedPath = pmssUserFileHandleProcPath($handle);
        $openedPath === null || pmssUserFileApplyMetadata($openedPath, $owner, $mode, $group);
    } finally {
        @fclose($handle);
    }
}

/** Converge an account file's mode as its owner; older ownership is repaired by the managed path. */
function pmssAccountFileApplyMetadata(string $username, string $home, string $path, int $mode): void
{
    if ($mode < 0 || $mode > 0777 || !pmssUserFilePathIsSafe($path) || !is_file($path)) {
        error_log("Unable to update account file mode for {$username}: {$path}");
        return;
    }
    $account = pmssAccountPathIdentity($username, $home);
    if (is_array($account) && @fileowner($path) === $account['uid']
        && pmssAccountPathRun($username, $home, [$path],
            'find '.escapeshellarg($path).' -maxdepth 0 -type f -links 1 -exec chmod '.sprintf('%o', $mode).' {} +')) {
        return;
    }
    error_log("Unable to update account file mode for {$username}: {$path}");
}

/** Prepare replacement metadata before publication; failed permissions leave the old file intact. */
function pmssUserFilePrepareMetadata(string $path, int $mode, ?string $owner, ?string $group): bool
{
    if ($mode < 0 || $mode > 07777 || !@chmod($path, $mode)) {
        return false;
    }
    if ($owner === null || !function_exists('posix_geteuid') || @posix_geteuid() !== 0) {
        return true;
    }

    return pmssUserFileOwnershipSet($path, $owner, ($group === null || $group === '') ? $owner : $group);
}
