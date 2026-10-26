<?php
/** Customer-tree copy of the bounded runtime reader; no operator includes (ADR 0016). */
namespace PMSS\Customer;
/** Refuse links/FIFOs before opening, then verify the nonblocking handle and byte bound. */
function pmssReadRegularFileContentsVerified(string $path, ?int $requiredUid = null, int $maxBytes = 4096, ?array &$metadata = null): ?string
{
    $metadata = null;
    if ($path === '' || $path[0] !== '/' || strpos($path, "\0") !== false
        || $maxBytes < 1 || $maxBytes === PHP_INT_MAX) return null;
    clearstatcache(true, $path);
    $entry = @lstat($path);
    if (!is_array($entry) || ($entry['mode'] & 0170000) !== 0100000 || $entry['nlink'] !== 1) return null;
    $handle = @fopen($path, 'rbn');
    if (!is_resource($handle)) return null;
    try {
        $opened = @fstat($handle);
        clearstatcache(true, $path);
        $current = @lstat($path);
        foreach ([$opened, $current] as $stat) {
            if (!is_array($stat) || $stat['dev'] !== $entry['dev'] || $stat['ino'] !== $entry['ino']
                || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
                || ($requiredUid !== null && $stat['uid'] !== $requiredUid) || $stat['size'] > $maxBytes) return null;
        }
        $raw = @stream_get_contents($handle, $maxBytes + 1);
        if (!is_string($raw) || strlen($raw) > $maxBytes || strlen($raw) !== $opened['size']) return null;
        $metadata = $opened;
        return $raw;
    } finally { fclose($handle); }
}
