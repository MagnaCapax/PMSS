<?php
/** Guarded log directory preparation, append operations and console mirroring. @license GPL-3.0-only */

/** Validate a log write target before appending data. */
function pmssLogWritePathIsSafe(string $path): bool
{
    // Check raw bytes before trim() can hide a malformed filesystem target.
    if (preg_match('/[\r\n\0]/', $path) === 1 || ($path = trim($path)) === '') {
        return false;
    }

    if (!pmssPathSegmentsAreSafe($path, true) || !pmssLockFilePathIsSafe($path)) {
        return false;
    }

    $directory = dirname($path);
    return $directory !== '' && is_dir($directory) && !is_link($directory);
}

/** Prepare a log parent directory without crossing unsafe path segments. */
function pmssLogWriteDirectoryPrepare(
    string $directory,
    int $mode = 0755,
    ?string &$error = null,
    bool $allowExistingLeafFile = false
): bool {
    $error = null;
    if ($directory === '' || ($directory !== '.' && !pmssPathSegmentsAreSafe($directory, true, true, !$allowExistingLeafFile, true))) {
        $error = 'unsafe';
        return false;
    }
    if ($directory !== '.' && !(is_dir($directory) || @mkdir($directory, $mode, true) || is_dir($directory))) {
        $error = 'create';
        return false;
    }

    return true;
}

/**
 * A log in a shared directory such as /tmp is written only when it is our own regular file with one name.
 * A missing file is created exclusively (O_EXCL), so a name another account placed first is never written to.
 * The directory must stop other accounts from renaming or removing our file (sticky, or ours and not
 * group/other-writable), so the by-path write after this check still reaches the file that was checked.
 */
function pmssLogSharedFileIsOwn(string $path, int $mode): bool
{
    if (!function_exists('posix_geteuid')) {
        return false;
    }
    $directory = @stat(dirname($path));
    if (!is_array($directory) || (($directory['mode'] & 01000) === 0
        && ($directory['uid'] !== posix_geteuid() || ($directory['mode'] & 0022) !== 0))) {
        return false;
    }
    clearstatcache(true, $path);
    if (!pmssPathExistsOrLink($path)) {
        $handle = pmssFileOpenWithUmask($path, 'x', 0777 & ~$mode);
        $handle !== false && fclose($handle);
        clearstatcache(true, $path);
    }
    $stat = @lstat($path);
    if (!pmssLogStatIsOwnSingleRegular($stat)) {
        return false;
    }
    // Narrow an older, wider-mode copy of our own file before anything new is written to it; never widen here.
    return ($stat['mode'] & 0777 & ~$mode) === 0 || @chmod($path, $stat['mode'] & 0777 & $mode);
}

/** lstat data of a regular file with one name, owned by the effective uid of this process. */
function pmssLogStatIsOwnSingleRegular($stat): bool
{
    return function_exists('posix_geteuid') && pmssUserFileStatIsSingleRegular($stat) && ($stat['uid'] ?? null) === posix_geteuid();
}

/** Append one timestamped line to a log file. */
function pmssLogAppendTimestampedLine(string $path, string $message, string $timestampFormat = '[Y-m-d H:i:s] ', string $prefix = '', ?int $mode = null): bool
{
    // A requested mode marks a shared-directory log: the file must be ours before anything is written to it.
    if (!pmssLogWritePathIsSafe($path) || ($mode !== null && !pmssLogSharedFileIsOwn($path, $mode))) {
        return false;
    }
    // A requested mode applies at creation too, so a new file never exists with wider permissions.
    $umask = $mode !== null ? umask(0777 & ~$mode) : null;
    try {
        // Keep incomplete writes on the existing failure/fallback path.
        $written = pmssFileWriteComplete($path, pmssLogFormatTimestampedLine($message, $timestampFormat, $prefix), FILE_APPEND | LOCK_EX);
    } finally {
        // Formatting and I/O throwables propagate only after restoring caller state.
        if ($umask !== null) umask($umask);
    }
    $written && $mode !== null && @chmod($path, $mode);
    return $written;
}

/** Mirror one message into PMSS log files and the active console stream. */
function pmssLogWriteMessage(string $primary, string $fallback, string $message, bool $writeToStderr = false): void
{
    $written = pmssLogAppendTimestampedLine($primary, $message);
    // The fallback lives in a shared directory such as /tmp: keep it owner-only.
    $written || pmssLogAppendTimestampedLine($fallback, $message, '[Y-m-d H:i:s] ', '', 0600);
    // Cron appends a job's output to the same file (">> log 2>&1"); echoing there would write each line twice.
    if ($written && pmssLogStreamIsFile($writeToStderr ? 'STDERR' : 'STDOUT', $primary)) return;
    $writeToStderr ? fwrite(STDERR, $message.PHP_EOL) : print($message.PHP_EOL);
}

/** True when the named standard stream is open on the file at $path (same device and inode). */
function pmssLogStreamIsFile(string $stream, string $path): bool
{
    // Closed standard streams and non-stream constants cannot supply inode metadata.
    if (!defined($stream) || !pmssStreamHandleIsOpen($handle = constant($stream))) return false;
    $open = @fstat($handle);
    $file = @stat($path);
    return is_array($open) && is_array($file) && $file['ino'] !== 0 && pmssFilesystemStatSameInode($open, $file);
}
