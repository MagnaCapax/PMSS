<?php
/**
 * rTorrent Unix socket listen-queue helpers.
 */

/**
 * Parse `ss -xln` output and return the queue depth for one Unix socket.
 *
 * @param string[] $lines
 * @return array{recvQ:int,sendQ:int}|null
 */
function rtorrentScgiSocketQueueSnapshotFromLines(array $lines, string $socketPath): ?array
{
    if ($socketPath === '') {
        return null;
    }

    foreach ($lines as $line) {
        $columns = preg_split('/\s+/', trim((string) $line));
        if (!is_array($columns) || count($columns) < 5 || ($columns[1] ?? '') !== 'LISTEN') {
            continue;
        }

        $recvQ = (string) ($columns[2] ?? '');
        $sendQ = (string) ($columns[3] ?? '');
        if (!in_array($socketPath, $columns, true) || !ctype_digit($recvQ) || !ctype_digit($sendQ)) {
            continue;
        }

        return [
            'recvQ' => (int) $recvQ,
            'sendQ' => (int) $sendQ,
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
    if ($socketPath === '') {
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
