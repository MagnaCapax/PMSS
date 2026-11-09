<?php
/** Stream acquisition, blocking writes and open-stream validation. @license GPL-3.0-only */

/**
 * Open with a caller-selected creation mask, restoring process state before returning or throwing.
 * Path checks, open mode, stream ownership and cleanup remain with the caller.
 * @return resource|false
 */
function pmssFileOpenWithUmask(string $path, string $mode, int $mask)
{
    $previous = umask($mask);
    try {
        return @fopen($path, $mode);
    } finally {
        umask($previous);
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
