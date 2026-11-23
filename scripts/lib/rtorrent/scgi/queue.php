<?php
/**
 * rTorrent Unix socket listen-queue helpers.
 */

require_once dirname(__DIR__, 2).'/runtime.php';

/**
 * Parse `ss -xln` output and return the queue depth for one Unix socket.
 *
 * @param string[] $lines
 * @return array{recvQ:int,sendQ:int}|null
 */
function rtorrentScgiSocketQueueSnapshotFromLines(array $lines, string $socketPath): ?array
{
    if ($socketPath === '' || pmssFilesystemPathHasNulByte($socketPath)) {
        return null;
    }

    foreach ($lines as $line) {
        $line = (string) $line;
        // Reject corrupt observations before trim can erase leading/trailing NULs.
        if (pmssFilesystemPathHasNulByte($line)) continue;
        $columns = pmssConfigLineColumns($line, 5, []);
        if ($columns === [] || $columns[1] !== 'LISTEN' || !in_array($socketPath, $columns, true)) {
            continue;
        }

        $recvQ = pmssUnsignedDecimalIntParse($columns[2]);
        $sendQ = pmssUnsignedDecimalIntParse($columns[3]);
        if ($recvQ === null || $sendQ === null) {
            continue;
        }

        return [
            'recvQ' => $recvQ,
            'sendQ' => $sendQ,
        ];
    }

    return null;
}

/**
 * Read the rTorrent SCGI socket accept queue from `ss -xln`.
 *
 * @return array{recvQ:int,sendQ:int}|null
 */
function rtorrentScgiSocketQueueSnapshot(string $socketPath): ?array
{
    // Malformed selectors cannot identify a Unix socket; skip the host probe too.
    if ($socketPath === '' || pmssFilesystemPathHasNulByte($socketPath)) {
        return null;
    }

    $out = [];
    $rc = 1;
    @exec('ss -xln 2>/dev/null', $out, $rc);

    return $rc === 0 ? rtorrentScgiSocketQueueSnapshotFromLines($out, $socketPath) : null;
}

/**
 * Decide whether the SCGI listen queue is saturated enough to signal a wedge.
 *
 * @param array{recvQ:int,sendQ:int} $snapshot
 */
function rtorrentScgiSocketQueueSaturated(array $snapshot): bool
{
    $sendQ = (int) ($snapshot['sendQ'] ?? 0);

    return $sendQ > 0 && (int) ($snapshot['recvQ'] ?? 0) >= $sendQ;
}

/**
 * Get the standard socket path for a user's rTorrent instance.
 */
function rtorrentScgiSocketPath(string $username): string
{
    return '/home/' . $username . '/.rtorrent.socket';
}

/**
 * True while the SCGI socket path still exists on the filesystem.
 *
 * The listener can outlive its path (the entry was removed while the process kept
 * the bind), so the stat cache is cleared before looking.
 */
function rtorrentScgiSocketFileExists(string $socketPath): bool
{
    if ($socketPath === '' || pmssFilesystemPathHasNulByte($socketPath)) {
        return false;
    }
    clearstatcache(true, $socketPath);

    return file_exists($socketPath);
}