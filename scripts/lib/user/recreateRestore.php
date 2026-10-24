<?php
/**
 * Restore account state after preserving checked top-level moves.
 * @license GPL-3.0-only
 */
require_once __DIR__.'/recreateRestoreSafety.php';
require_once dirname(__DIR__).'/lighttpd/userFileWrite.php';

/** Move one whole home directory under its trusted parent without overwriting data. */
function pmssRecreateMoveTopDirectory(string $source, string $destination): void
{
    clearstatcache(true, $source);
    clearstatcache(true, $destination);
    if (dirname($source) !== dirname($destination)
        || !pmssPathTargetIsSafe(dirname($source), true)
        || !pmssPathTargetIsSafe($source, true, true) || !is_dir($source)
        || !pmssPathTargetIsSafe($destination, true, true)
        || file_exists($destination) || is_link($destination)) {
        throw new RuntimeException('Unsafe top-level recreate move: '.$source);
    }
    $before = @lstat($source);
    if (!is_array($before) || !@rename($source, $destination)) {
        throw new RuntimeException('Unable to move recreate directory: '.$source);
    }
    clearstatcache(true, $destination);
    $after = @lstat($destination);
    if (!is_array($after) || !pmssFilesystemStatSameInode($before, $after)) {
        throw new RuntimeException('Recreate move identity mismatch: '.$destination);
    }
}

/** Remove a superseded archive only through the pinned real tree. */
function pmssRecreatePurgeSupersededDirectory(string $path): bool
{
    clearstatcache(true, $path);
    if (!pmssPathTargetIsSafe($path, true, true) || !is_dir($path)) return false;
    foreach ([false, true, false] as $clearImmutable) {
        $output = [];
        exec(pmssPinnedTreePurgeCommand($path, $clearImmutable).' 2>&1', $output, $rc);
    }
    clearstatcache(true, $path);
    return !file_exists($path) && !is_link($path);
}

/** Copy the checked single-link regular source inode to a regular-file destination beneath real directories. */
function pmssRecreateCopyFile(string $source, string $destination, ?int $requiredUid = null, ?int $targetUid = null, ?int $targetGid = null): bool
{
    if (!file_exists($source) && !is_link($source)) {
        return false;
    }
    clearstatcache(true, $source);
    if (is_link($source) || !pmssPathTargetIsSafe($source, false, true)
        || pmssRecreateHasLinkedParent($source)) {
        return false;
    }
    // One non-blocking open: the handle must be the lstat-named inode (regular, one link, required owner).
    $content = pmssReadRegularFileContentsVerified($source, $requiredUid, 1048576);
    if ($content === null) {
        return false;
    }
    clearstatcache(true, $destination);
    if (!pmssPathTargetIsSafe($destination, false, true)
        || pmssRecreateHasLinkedParent($destination) || !is_dir(dirname($destination)) || is_link($destination)
        || (file_exists($destination) && !is_file($destination))) {
        throw new RuntimeException('Unsafe restore file: '.$destination);
    }
    // Stage a private exclusive file beside the destination, set metadata on the opened inode, then rename it.
    $handle = pmssUserFileTempCreate($destination, $tmp);
    if ($handle === null) {
        throw new RuntimeException('Unable to copy restore file: '.$source);
    }
    try {
        $opened = pmssUserFileHandleWriteComplete($handle, $content) ? pmssUserFileHandleProcPath($handle) : null;
        if ($opened === null || !@chmod($opened, 0640)
            || ($targetUid !== null && ($targetGid === null || !pmssUserFileOwnershipSet($opened, $targetUid, $targetGid)))) {
            throw new RuntimeException('Unable to set restored file ownership: '.$destination);
        }
        clearstatcache(true, $destination);
        if (!pmssUserFileHandleMatchesEntry($handle, $tmp) || is_link($destination)
            || (file_exists($destination) && !is_file($destination)) || !@rename($tmp, $destination)) {
            throw new RuntimeException('Unable to copy restore file: '.$source);
        }
        $tmp = null;
    } finally {
        @fclose($handle);
        if (is_string($tmp)) @unlink($tmp);
    }
    return true;
}

/** Restore preserved state and return paths kept in the archive for review. */
function pmssRecreateRestoreHome(string $home, string $backup, bool $hadHome, int $uid, int $gid, int $identityUid = 0, int $privateUid = 0, ?callable $onBilling = null): array
{
    pmssRecreateRequirePrivateDirectory($home, $privateUid);
    if ($hadHome) {
        pmssRecreateRequirePrivateDirectory($backup, $privateUid);
    }
    if ($hadHome) {
        // The fresh skeleton has a populated bin; archive that generated copy
        // before restoring the customer's complete bin tree and its selections.
        if (file_exists($backup.'/bin') || is_link($backup.'/bin')) {
            if (is_link($backup.'/bin') || !is_dir($backup.'/bin')) {
                throw new RuntimeException('Unsafe restore directory: '.$backup.'/bin');
            }
            pmssRecreateMoveDirectory($home.'/bin', $backup.'/.pmss-recreate-bin-skel');
        }
        foreach (['data', 'session', 'bin'] as $dir) {
            pmssRecreateMoveDirectory($backup.'/'.$dir, $home.'/'.$dir);
        }
        $share = $backup.'/.local/share/pmss';
        if (file_exists($share) || is_link($share)) {
            pmssRecreateEnsureDirectory($home.'/.local', $uid, $gid);
            pmssRecreateEnsureDirectory($home.'/.local/share', $uid, $gid);
            pmssRecreateMoveDirectory($share, $home.'/.local/share/pmss');
        }
    }
    foreach (['data', 'session', '.lighttpd'] as $dir) {
        pmssRecreateEnsureDirectory($home.'/'.$dir, $uid, $gid);
    }
    if (!$hadHome) {
        return [];
    }
    if ($onBilling !== null) {
        $onBilling();
    }
    $leftBehind = [];
    if (is_dir($backup.'/.pmss-recreate-bin-skel')) {
        $leftBehind[] = $backup.'/.pmss-recreate-bin-skel';
    }
    foreach (['.billingServiceId', '.billingId', '.billingClientId', '.notifyEmail'] as $name) {
        $source = $backup.'/'.$name;
        if ((file_exists($source) || is_link($source))
            && !pmssRecreateCopyFile($source, $home.'/'.$name, $identityUid, $identityUid, $gid)) {
            // Billing sources that do not meet the ownership and file-type contract stay in the archive.
            $leftBehind[] = $source;
        }
    }
    $credential = $backup.'/.lighttpd/.htpasswd';
    if ((file_exists($credential) || is_link($credential))
        && !pmssRecreateCopyFile($credential, $home.'/.lighttpd/.htpasswd', $uid, $uid, $gid)) {
        throw new RuntimeException('Refusing unverified credential file: '.$credential);
    }
    // These customer overrides are intentionally retained for manual review.
    foreach (['.config/pmss', '.rtorrent.rc.custom', '.lighttpd/custom', '.lighttpd/custom.d',
        'watch', 'www/public', 'www/rutorrent/share'] as $relative) {
        $path = $backup.'/'.$relative;
        if (file_exists($path) || is_link($path)) {
            $leftBehind[] = $path;
        }
    }
    return $leftBehind;
}
