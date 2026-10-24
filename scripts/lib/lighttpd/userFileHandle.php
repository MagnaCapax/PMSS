<?php
/** Verified file handles and exclusive staging creation. @license GPL-3.0-only */

/** A /proc/self/fd/N path names an inode this process already holds open. */
function pmssUserFileOpenedPathIs(string $path): bool { return preg_match('#\A/proc/self/fd/[0-9]+\z#', $path) === 1; }

/** Confirm a directory entry is the single-link regular file behind an open handle. */
function pmssUserFileHandleMatchesEntry($handle, string $path): bool
{
    clearstatcache(true, $path);
    $entry = @lstat($path);
    return pmssUserFileStatIsSingleRegular($entry)
        && pmssLockFileHandleMatchesPath($handle, $path, $entry, $opened)
        && pmssUserFileStatIsSingleRegular($opened);
}

/**
 * Open an existing single-link regular file and prove the handle is that entry.
 * PHP resolves links in user space before open(2) and may reuse its realpath
 * cache, so the post-open comparison, not the pre-open lstat, is the guarantee.
 * The mode must not create files; 'n' keeps a swapped-in FIFO from blocking.
 *
 * @return resource|null
 */
function pmssUserFileOpenVerified(string $path, string $mode)
{
    clearstatcache(true);
    if (!pmssUserFilePathIsSafe($path) || !pmssUserFileStatIsSingleRegular(@lstat($path))) {
        return null;
    }
    $handle = @fopen($path, $mode);
    if (!pmssStreamHandleIsOpen($handle)) {
        return null;
    }
    if (!pmssUserFileHandleMatchesEntry($handle, $path)) {
        @fclose($handle);
        return null;
    }
    return $handle;
}

/**
 * Return /proc/self/fd/N for an open handle, found by matching device and inode.
 * Metadata calls on that path act on the opened inode whatever its name now is.
 */
function pmssUserFileHandleProcPath($handle): ?string
{
    $opened = pmssStreamHandleIsOpen($handle) ? @fstat($handle) : false;
    $entries = pmssDirectoryEntriesRead('/proc/self/fd');
    if (!is_array($opened) || !is_array($entries)) {
        return null;
    }
    foreach ($entries as $entry) {
        $candidate = '/proc/self/fd/'.$entry;
        clearstatcache(true, $candidate);
        $stat = ctype_digit((string) $entry) ? @stat($candidate) : false;
        if (is_array($stat) && pmssFilesystemStatSameInode($stat, $opened)) {
            return $candidate;
        }
    }
    return null;
}

/** Write the whole payload through an open handle; any failed or short write reports false. */
function pmssUserFileHandleWriteComplete($handle, string $content): bool
{
    return ($content === '' || @fwrite($handle, $content) === strlen($content)) && @fflush($handle);
}

/**
 * Create a private staging file beside $path under a random name.
 * The handle is returned only when the new name is a single-link regular file
 * that is the opened inode; $tmp receives that name.
 *
 * @return resource|null
 */
function pmssUserFileTempCreate(string $path, ?string &$tmp)
{
    $tmp = null;
    // Same bound tempnam() applied to prefixes, so long basenames stay valid names.
    $prefix = dirname($path).'/'.substr(basename($path).'.pmss-tmp-', 0, 63);
    for ($attempt = 0; $attempt < 4; $attempt++) {
        try {
            $candidate = $prefix.bin2hex(random_bytes(6));
        } catch (\Throwable $error) {
            return null;
        }
        clearstatcache(true);
        $umask = umask(0077);
        try {
            $handle = @fopen($candidate, 'xb');
        } finally {
            umask($umask);
        }
        if (!pmssStreamHandleIsOpen($handle)) {
            clearstatcache(true, $candidate);
            if (pmssPathExistsOrLink($candidate)) {
                continue;
            }
            return null;
        }
        if (pmssUserFileHandleMatchesEntry($handle, $candidate)) {
            $tmp = $candidate;
            return $handle;
        }
        // The open did not land on the entry just named; that entry is not ours to remove.
        @fclose($handle);
        return null;
    }
    return null;
}
