<?php
/** Atomic replacement and verified append operations. @license GPL-3.0-only */

/**
 * Atomically replace a regular file, with optional temp-file preparation.
 * Incomplete writes leave the destination untouched and remove the temp file.
 * The directory may be writable by the account, which can rename or replace
 * the staging name at any moment. Content is therefore written through the
 * open handle, and $prepareTemp receives /proc/self/fd/N for that handle, not
 * the staging name, so its chmod/chown calls reach only the staged inode.
 */
function pmssReplaceUserFile(string $path, string $content, ?callable $prepareTemp = null): bool
{
    if (pmssManagedAccountHomePath($path) || !pmssUserFilePathIsSafe($path)) {
        return false;
    }

    $handle = pmssUserFileTempCreate($path, $tmp);
    if ($handle === null) {
        return false;
    }

    try {
        if (!pmssUserFileHandleWriteComplete($handle, $content)) {
            return false;
        }
        if ($prepareTemp !== null) {
            $openedPath = pmssUserFileHandleProcPath($handle);
            if ($openedPath === null || $prepareTemp($openedPath) === false) {
                return false;
            }
        }
        // Publish only the staged inode: the name must still be that single-link file.
        if (!pmssUserFileHandleMatchesEntry($handle, $tmp) || !pmssUserFilePathIsSafe($path) || !@rename($tmp, $path)) {
            return false;
        }

        $tmp = null;
        return true;
    } finally {
        // Failed operations and thrown I/O errors must not strand staging files.
        @fclose($handle);
        if (is_string($tmp)) @unlink($tmp);
    }
}

function pmssReplaceUserFileWithMetadata(string $path, string $content, int $mode, ?string $owner = null, ?string $group = null): bool
{
    if (pmssManagedAccountHomePath($path)) {
        $context = pmssRootStageContext($path);
        $identity = pmssAccountHomeIdentity($path);
        if ($identity === null) return false;
        list($home, $user) = $identity;
        if ($owner === 'root' || $owner === 0 || $owner === null) {
            if ($context === null) return false;
            return pmssRootStageFileWrite($path, $content, $group === null ? 'root' : $group, $mode);
        }
        if ($owner === $user && ($context !== null || @posix_geteuid() === $identity[2])) {
            return pmssReplaceAccountFile($user, $home, $path, $content, $mode, true);
        }
        return false;
    }
    return pmssReplaceUserFile($path, $content, static function (string $tmpPath) use ($mode, $owner, $group): bool {
        return pmssUserFilePrepareMetadata($tmpPath, $mode, $owner, $group);
    });
}

/** Replace a file while keeping existing mode/uid/gid when available. */
function pmssReplaceUserFilePreservingMetadata(string $path, string $content, int $fallbackMode = 0644): bool
{
    if (pmssManagedAccountHomePath($path)) {
        $context = pmssRootStageContext($path);
        $identity = pmssAccountHomeIdentity($path);
        if ($identity === null) return false;
        list($home, $user) = $identity;
        $entry = @lstat($path);
        if (is_array($entry) && !pmssUserFileStatIsSingleRegular($entry)) return false;
        $mode = is_array($entry) ? $entry['mode'] & 0777 : $fallbackMode;
        $account = @posix_getpwnam($user);
        if (!is_array($account)) return false;
        if (is_array($entry) && $entry['uid'] === 0) {
            if ($context === null) return false;
            $group = @posix_getgrgid($entry['gid']);
            return is_array($group) && pmssRootStageFileWrite($path, $content, $group['name'], $mode);
        }
        if (is_array($entry) && $entry['uid'] !== $account['uid']) return false;
        if ($context === null && @posix_geteuid() !== $identity[2]) return false;
        return pmssReplaceAccountFile($user, $home, $path, $content, $mode, true);
    }
    $mode = $fallbackMode;
    $owner = null;
    $group = null;
    $canAdjustOwnership = function_exists('posix_geteuid') && @posix_geteuid() === 0;

    if (is_file($path) && is_array($stat = @stat($path))) {
        $mode = $stat['mode'] & 0777;
        if ($canAdjustOwnership) {
            $owner = $stat['uid'];
            $group = $stat['gid'];
        }
    }

    return pmssReplaceUserFile($path, $content, static function (string $tmpPath) use ($group, $mode, $owner): bool {
        // Owner and group are captured together; numeric ids from stat() stay numeric.
        return pmssUserFilePrepareMetadata($tmpPath, $mode, null, null)
            && ($owner === null || pmssUserFileOwnershipSet($tmpPath, $owner, $group));
    });
}

/**
 * Append content to a regular user-owned file when the target path is safe.
 *
 * This keeps legacy append workflows on the same path validation rules as the
 * atomic writer so symlinks and non-regular targets are rejected consistently.
 * A short append reports failure; bytes already appended cannot be rolled back.
 *
 * A missing file is staged under a random name and published by rename: PHP
 * resolves a link planted at the predictable name before open(2), so creating
 * it in place could create a file elsewhere. An existing file must be a
 * single-link regular file and is written and chmodded through its handle.
 */
function pmssAppendUserFile(string $path, string $content, string $owner, int $mode): bool
{
    if (pmssManagedAccountHomePath($path)) {
        $context = pmssRootStageContext($path);
        return $context !== null && $owner === $context[2]
            && pmssAppendAccountFile($owner, $context[1], $path, $content, $mode);
    }
    if (!pmssUserFilePathIsSafe($path)) {
        return false;
    }

    clearstatcache(true, $path);
    if (!pmssPathExistsOrLink($path) && pmssReplaceUserFile($path, $content,
        static function (string $openedPath) use ($path, $owner, $mode): bool {
            pmssUserFileApplyMetadata($openedPath, $owner, $mode);
            // If another writer has already published, append to its file below.
            clearstatcache(true, $path);
            return !pmssPathExistsOrLink($path);
        })) {
        return true;
    }

    // 'r+' never creates, so a swapped-in dangling link cannot make a new file.
    $handle = pmssUserFileOpenVerified($path, 'r+bn');
    if ($handle === null) {
        return false;
    }
    try {
        if (!@flock($handle, LOCK_EX) || !pmssUserFileHandleMatchesEntry($handle, $path)
            || @fseek($handle, 0, SEEK_END) !== 0
            || !pmssUserFileHandleWriteComplete($handle, $content)) {
            return false;
        }
        $openedPath = pmssUserFileHandleProcPath($handle);
        $openedPath === null || pmssUserFileApplyMetadata($openedPath, $owner, $mode);
        return true;
    } finally {
        @fclose($handle);
    }
}
