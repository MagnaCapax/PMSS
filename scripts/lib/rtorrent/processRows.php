<?php
/** Pure rTorrent executor and restart-safety process row decoding. @license Proprietary */

require_once dirname(__DIR__).'/runtime/environment.php';

/**
 * Parse a positive PID without allowing PHP's coercion or integer saturation.
 *
 * @param mixed $pid Candidate process identifier.
 * @return int|null Validated PID, retaining ordinary whitespace normalization.
 */
function rtorrentProcessPidParse($pid): ?int
{
    if ((!is_int($pid) && !is_string($pid)) || (is_string($pid) && strpos($pid, "\0") !== false)) {
        return null;
    }
    $value = pmssUnsignedDecimalIntParse(trim((string) $pid));
    return $value !== null && $value > 0 ? $value : null;
}

/**
 * Normalize a PID list to unique positive integers.
 *
 * @param mixed $pids Candidate PID list from a probe callback.
 *
 * @return int[] Unique positive PIDs.
 */
function rtorrentProcessNormalizePids($pids): array
{
    if (!is_array($pids)) {
        return [];
    }

    $normalized = [];
    foreach ($pids as $pid) {
        $pid = rtorrentProcessPidParse($pid);
        if ($pid !== null) $normalized[$pid] = $pid;
    }

    return array_values($normalized);
}


/** Decode executor rows in encounter order, retaining duplicate PIDs and unknown commands in all. */
function rtorrentProcessExecutorPidsFromPsLines(array $lines): array
{
    $pids = ['php' => [], 'screen' => [], 'all' => []];
    foreach (pmssConfigLines($lines, []) as $line) {
        if (strpos($line, 'rtorrentExecute.php') === false
            || !preg_match('/^(\\d+)\\s+(\\S+)\\s+(.+)$/', $line, $m)
            || ($pid = rtorrentProcessPidParse($m[1])) === null) {
            continue;
        }
        $pids['all'][] = $pid;
        $comm = strtolower((string) $m[2]);
        // comm may show 'php' or script name '.rtorrentexecute' (lowercased).
        if (strpos($comm, 'php') === 0 || strpos($comm, '.rtorrentexecute') === 0) {
            $pids['php'][] = $pid;
        } elseif (strpos($comm, 'screen') !== false) {
            $pids['screen'][] = $pid;
        }
    }
    return $pids;
}

/**
 * Parse one `ps -o pid=,stat=,wchan=` row for restart-safety checks.
 *
 * @param string $line One trimmed or untrimmed ps output row.
 *
 * @return array{pid:int,stat:string,wchan:string}|null Parsed process state.
 */
function rtorrentProcessStateFromPsLine(string $line): ?array
{
    $line = trim($line);
    if (!preg_match('/^(\d+)\s+(\S+)(?:\s+(\S+))?$/', $line, $m)
        || ($pid = rtorrentProcessPidParse($m[1])) === null) {
        return null;
    }

    return [
        'pid' => $pid,
        'stat' => (string) $m[2],
        'wchan' => (string) ($m[3] ?? ''),
    ];
}

/**
 * Parse process-state rows into a PID-keyed map.
 *
 * @param string[] $lines Output rows from `ps -o pid=,stat=,wchan=`.
 *
 * @return array<int, array{pid:int,stat:string,wchan:string}> Process states keyed by PID.
 */
function rtorrentProcessStatesFromPsLines(array $lines): array
{
    $states = [];
    foreach ($lines as $line) {
        $state = rtorrentProcessStateFromPsLine((string) $line);
        if ($state !== null) $states[$state['pid']] = $state;
    }

    return $states;
}

/**
 * Detect uninterruptible I/O sleep, where killing and restarting is unsafe.
 *
 * @param array<int, array{pid:int,stat:string,wchan:string}> $states Process states.
 *
 * @return bool True when any process STAT contains D.
 */
function rtorrentProcessStatesHaveUninterruptibleIo(array $states): bool
{
    foreach ($states as $state) {
        if (strpos((string) ($state['stat'] ?? ''), 'D') !== false) return true;
    }

    return false;
}
