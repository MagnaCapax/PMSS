<?php
/**
 * Private restore-path checks and safe directory moves.
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
    // A restore move must retain its inode; refuse another device before removing a placeholder.
    $sourceStat = @lstat($source);
    $parentStat = @stat(dirname($destination));
    if (!is_array($sourceStat) || ($sourceStat['mode'] & 0170000) !== 0040000
        || !is_array($parentStat) || $sourceStat['dev'] !== $parentStat['dev']) {
        throw new RuntimeException('Restore directory is not on the destination filesystem: '.$source);
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
    clearstatcache(true, $destination);
    $movedStat = @lstat($destination);
    if (!is_array($movedStat) || ($movedStat['mode'] & 0170000) !== 0040000
        || !pmssFilesystemStatSameInode($movedStat, $sourceStat)) {
        throw new RuntimeException('Restore move identity mismatch: '.$destination);
    }
    return true;
}
