<?php
/** Customer-local support dependencies; no operator bootstrap. @license GPL-3.0-only */

require_once __DIR__.'/localFilesystem.php';

/** Local copy of scripts/lib/runtime/filesystemRead.php::pmssReadRegularFileContentsVerified; preserve its guards and behavior. */
function pmssSupportLocalReadRegularFileContentsVerified(string $path, ?int $requiredUid = null, int $maxBytes = 4096, ?array &$metadata = null): ?string { return pmssSupportLocalRegularFileRead($path, $requiredUid, $maxBytes, $metadata); }

/** Local copy of scripts/lib/runtime/filesystemRead.php::pmssRegularFileRead; preserve its guards and behavior. */
function pmssSupportLocalRegularFileRead(string $path, ?int $requiredUid = null, ?int $maxBytes = null, ?array &$metadata = null): ?string
{
    $metadata = null;
    if ($path === '' || pmssSupportLocalFilesystemPathHasNulByte($path)) return null;
    // Bounded reads require absolute paths and a representable one-byte overread.
    if ($maxBytes !== null && ($path[0] !== '/' || $maxBytes < 1 || $maxBytes === PHP_INT_MAX)) return null;
    clearstatcache(true, $path);
    $entry = @lstat($path);
    if (!pmssSupportLocalUserFileStatIsSingleRegular($entry)) return null;
    $handle = pmssSupportLocalRegularFileOpenVerified($path, $entry, $opened);
    if ($handle === false) return null;
    try {
        if (!pmssSupportLocalUserFileStatIsSingleRegular($opened)
            || ($requiredUid !== null && ($opened['uid'] ?? null) !== $requiredUid)
            || ($maxBytes !== null && ($opened['size'] ?? PHP_INT_MAX) > $maxBytes)) return null;
        $contents = $maxBytes === null ? @stream_get_contents($handle) : @stream_get_contents($handle, $maxBytes + 1);
        if (!is_string($contents) || ($maxBytes !== null && (strlen($contents) > $maxBytes || strlen($contents) !== $opened['size']))) return null;
    } finally {
        $closed = @fclose($handle);
    }
    // A failed close cannot certify contents or publish an opened-inode snapshot.
    if (!$closed) return null;
    $metadata = $opened;
    return $contents;
}

