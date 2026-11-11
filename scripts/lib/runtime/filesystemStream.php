<?php
/** Stream acquisition, blocking writes and open-stream validation. @license GPL-3.0-only */

/**
 * Open with a caller-selected creation mask, restoring process state before returning or throwing.
 * Reject malformed path/mode bytes; path policy, mode choice and stream ownership stay with the caller.
 * @return resource|false
 */
function pmssFileOpenWithUmask(string $path, string $mode, int $mask)
{
    // Keep malformed opens on the false-result path before changing process state.
    if ($path === '' || $mode === '' || strpos($path, "\0") !== false || strpos($mode, "\0") !== false) return false;
    $previous = umask($mask);
    try {
        return @fopen($path, $mode);
    } finally {
        umask($previous);
    }
}

/**
 * Open a regular inode without blocking on a substituted FIFO; transfer only verified streams.
 * Callers retain path/owner/link policy and may supply their already-validated stat or lstat snapshot.
 * @return resource|false Caller owns successful streams; rejected streams close here, even on throws.
 */
function pmssRegularFileOpenVerified(string $path, ?array $pathStat = null, ?array &$handleStat = null)
{
    $handleStat = null;
    // Supplied metadata must not bypass raw-path validation before fopen().
    if ($path === '' || pmssFilesystemPathHasNulByte($path)) return false;
    if ($pathStat === null) {
        clearstatcache(true, $path);
        $pathStat = @lstat($path);
    }
    if (!is_array($pathStat) || (($pathStat['mode'] ?? 0) & 0170000) !== 0100000) return false;
    $handle = @fopen($path, 'rbn');
    if (!pmssStreamHandleIsOpen($handle)) return false;
    $verified = false;
    try {
        $verified = pmssLockFileHandleMatchesPath($handle, $path, $pathStat, $handleStat);
        return $verified ? $handle : false;
    } finally {
        if (!$verified) fclose($handle);
    }
}

/** Reject closed streams and other resource types before stream operations. */
function pmssStreamHandleIsOpen($handle): bool { return is_resource($handle) && get_resource_type($handle) === 'stream'; }
/**
 * Write a blocking stream buffer completely; stop on failure or no progress, without flushing or closing.
 * @param resource $handle Caller-owned writable stream; empty payloads require no write.
 */
function pmssStreamWriteAll($handle, string $payload): bool
{
    $length = strlen($payload);
    for ($offset = 0; $offset < $length; $offset += $written) {
        // A wrapper may close the stream during a partial write; recheck before retrying.
        if (!pmssStreamHandleIsOpen($handle)) return false;
        $written = @fwrite($handle, substr($payload, $offset));
        if (!is_int($written) || $written < 1) return false;
    }
    return true;
}
