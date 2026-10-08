<?php
/**
 * Restore account state while the rebuilt home and archived home are private to root.
 *
 * Callers must keep both top-level directories root-owned and inaccessible to
 * the account until these operations have finished.
 *
 * @license GPL-3.0-only
 */
require_once dirname(__DIR__).'/pathSafety.php';

/** Prove each rebuild destination accepts a private exclusive create and write. */
function pmssRecreateRequireWritableDirectories(array $directories): void
{
    foreach ($directories as $directory) {
        if (!is_dir($directory) || is_link($directory)) {
            throw new RuntimeException('Rebuild write pre-flight failed for '.$directory);
        }
        try {
            $path = rtrim($directory, '/').'/.pmss-recreate-write-probe-'.bin2hex(random_bytes(16));
        } catch (Throwable $error) {
            throw new RuntimeException('Rebuild write pre-flight failed for '.$directory, 0, $error);
        }
        $oldUmask = umask(0077);
        $handle = @fopen($path, 'x+b'); // Exclusive create; the umask makes the initial mode 0600.
        umask($oldUmask);
        if ($handle === false) {
            throw new RuntimeException('Rebuild write pre-flight failed for '.$directory);
        }
        $written = @fwrite($handle, '1') === 1 && @fflush($handle);
        $closed = @fclose($handle);
        $removed = @unlink($path);
        if (!$written || !$closed || !$removed) {
            throw new RuntimeException('Rebuild write pre-flight failed for '.$directory);
        }
    }
}

/** Report whether a restore path has a linked parent directory. */
function pmssRecreateHasLinkedParent(string $path): bool
{
    for ($parent = dirname($path); $parent !== dirname($parent); $parent = dirname($parent)) {
        // A shell rebuild step may have replaced a parent since its last PHP check.
        clearstatcache(true, $parent);
        if (is_link($parent)) {
            return true;
        }
    }
    return false;
}

/** Require the top-level restore trees to be inaccessible to the account. */
function pmssRecreateRequirePrivateDirectory(string $path, int $ownerUid): void
{
    // Shell chown/chmod does not invalidate PHP's cached lstat.
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if (is_link($path) || !is_array($stat) || ($stat['mode'] & 0170000) !== 0040000
        || $stat['uid'] !== $ownerUid || ($stat['mode'] & 0077) !== 0) {
        throw new RuntimeException('Restore directory is not private: '.$path);
    }
}

/** Accept an absent or regular credential file beneath real directories. */
function pmssRecreateCredentialPathIsSafe(string $path): bool
{
    // Service configuration runs in shell commands after the restore checks.
    clearstatcache(true, $path);
    return !pmssRecreateHasLinkedParent($path) && !is_link($path)
        && (!file_exists($path) || is_file($path));
}

/** Ensure a restore directory exists and every path component is a real directory. */
function pmssRecreateEnsureDirectory(string $path, int $uid, int $gid): void
{
    $parent = dirname($path);
    if (pmssRecreateHasLinkedParent($path) || !is_dir($parent) || is_link($path) || (file_exists($path) && !is_dir($path))) {
        throw new RuntimeException('Unsafe restore directory: '.$path);
    }
    if (is_dir($path)) {
        return;
    }
    if (!@mkdir($path, 0750) || !@chown($path, $uid) || !@chgrp($path, $gid)) {
        throw new RuntimeException('Unable to create restore directory: '.$path);
    }
}

/** Rename a customer directory only into a vacant real directory. */
function pmssRecreateMoveDirectory(string $source, string $destination): bool
{
    if (!file_exists($source) && !is_link($source)) {
        return false;
    }
    if (is_link($source) || pmssRecreateHasLinkedParent($source) || !is_dir($source)
        || pmssRecreateHasLinkedParent($destination) || !is_dir(dirname($destination))
        || is_link($destination) || (file_exists($destination) && !is_dir($destination))) {
        throw new RuntimeException('Unsafe restore directory: '.$destination);
    }
    if (is_dir($destination)) {
        $entries = scandir($destination);
        $contents = $entries === false ? [] : array_values(array_diff($entries, ['.', '..']));
        // Skeleton placeholders are vacant; all other entries remain conflicts.
        if ($entries === false || (count($contents) > 0 && ($contents !== ['.gitkeep']
            || is_link($destination.'/.gitkeep') || !is_file($destination.'/.gitkeep')))) {
            throw new RuntimeException('Restore destination is not empty: '.$destination);
        }
        if ($contents !== [] && !@unlink($destination.'/.gitkeep')) {
            throw new RuntimeException('Unable to remove restore placeholder: '.$destination.'/.gitkeep');
        }
        if (!@rmdir($destination)) {
            throw new RuntimeException('Restore destination is not empty: '.$destination);
        }
    }
    // Both paths are beneath /home, so rename preserves the directory's inode and quota use.
    if (!@rename($source, $destination)) {
        throw new RuntimeException('Unable to move restore directory: '.$source);
    }
    return true;
}

/** Copy a regular source file to a regular-file destination beneath real directories. */
function pmssRecreateCopyFile(string $source, string $destination, ?int $requiredUid = null, ?int $targetUid = null, ?int $targetGid = null): bool
{
    if (!file_exists($source) && !is_link($source)) {
        return false;
    }
    $sourceStat = @lstat($source);
    clearstatcache(true, $source);
    if (is_link($source) || !pmssPathTargetIsSafe($source, false, true)
        || pmssRecreateHasLinkedParent($source)
        || !is_array($sourceStat) || ($sourceStat['mode'] & 0170000) !== 0100000
        || ($requiredUid !== null && $sourceStat['uid'] !== $requiredUid)) {
        return false;
    }
    clearstatcache(true, $destination);
    if (!pmssPathTargetIsSafe($destination, false, true)
        || pmssRecreateHasLinkedParent($destination) || !is_dir(dirname($destination)) || is_link($destination)
        || (file_exists($destination) && !is_file($destination))) {
        throw new RuntimeException('Unsafe restore file: '.$destination);
    }
    if (!@copy($source, $destination)) {
        throw new RuntimeException('Unable to copy restore file: '.$source);
    }
    clearstatcache(true, $destination);
    if (!pmssPathTargetIsSafe($destination, false, true) || !is_file($destination) || is_link($destination)) {
        throw new RuntimeException('Unsafe restored file: '.$destination);
    }
    if ($targetUid !== null && ($targetGid === null || !@chown($destination, $targetUid)
        || !@chgrp($destination, $targetGid) || !@chmod($destination, 0640))) {
        throw new RuntimeException('Unable to set restored file ownership: '.$destination);
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
        foreach (['data', 'session'] as $dir) {
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
        && !pmssRecreateCopyFile($credential, $home.'/.lighttpd/.htpasswd')) {
        throw new RuntimeException('Refusing non-regular credential file: '.$credential);
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
