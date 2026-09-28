<?php
/**
 * Process discovery, snapshots, and stability checks for rTorrent.
 *
 * @license Proprietary
 */

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
        $pid = (int) $pid;
        if ($pid > 0) $normalized[$pid] = $pid;
    }

    return array_values($normalized);
}

/**
 * Wait for a PID set to appear, then verify at least one original PID survives.
 *
 * The caller supplies a probe callback so tests can stay hermetic. Success only
 * occurs when the same observed PID still exists after the stability window.
 *
 * @param callable $pidProvider             Callback returning candidate PIDs.
 * @param float    $startupTimeoutSeconds   Seconds to wait for first appearance.
 * @param float    $stabilityWindowSeconds  Seconds the same PID must survive.
 * @param int      $pollMicroseconds        Poll interval in microseconds.
 *
 * @return bool True when at least one initially observed PID survives.
 */
function rtorrentProcessWaitForStablePids(
    callable $pidProvider,
    float $startupTimeoutSeconds,
    float $stabilityWindowSeconds,
    int $pollMicroseconds = 250000
): bool {
    $pollMicroseconds = max(1000, $pollMicroseconds);
    $startupTimeoutSeconds = max(0.0, $startupTimeoutSeconds);
    $stabilityWindowSeconds = max(0.0, $stabilityWindowSeconds);

    $initialPids = rtorrentProcessNormalizePids($pidProvider());
    if (empty($initialPids) && $startupTimeoutSeconds > 0.0) {
        $startupDeadline = microtime(true) + $startupTimeoutSeconds;
        while (microtime(true) < $startupDeadline) {
            usleep($pollMicroseconds);
            $initialPids = rtorrentProcessNormalizePids($pidProvider());
            if (!empty($initialPids)) {
                break;
            }
        }
    }

    if (empty($initialPids)) {
        return false;
    }

    if ($stabilityWindowSeconds > 0.0) {
        $stabilityDeadline = microtime(true) + $stabilityWindowSeconds;
        while (microtime(true) < $stabilityDeadline) {
            $remainingMicroseconds = (int) (($stabilityDeadline - microtime(true)) * 1000000);
            if ($remainingMicroseconds <= 0) {
                break;
            }
            usleep(min($pollMicroseconds, $remainingMicroseconds));
        }
    }

    $currentPids = rtorrentProcessNormalizePids($pidProvider());
    return !empty(array_intersect($initialPids, $currentPids));
}

/**
 * Locate executor-related processes for a user.
 *
 * Parses ps output to find screen sessions and PHP processes running
 * the .rtorrentExecute.php wrapper.
 *
 * @param string $user System username.
 *
 * @return array{php:int[],screen:int[],all:int[]} PIDs grouped by type.
 */
function rtorrentProcessExecutorPids(string $user, ?int &$rc = null, ?array &$output = null): array
{
    $processOutput = [];
    $processRc = 0;
    @exec('ps -u '.escapeshellarg($user).' -o pid=,comm=,args=', $processOutput, $processRc);
    $output = $processOutput;
    $rc = $processRc;
    if ($processRc !== 0) {
        return ['php' => [], 'screen' => [], 'all' => []];
    }

    $php = [];
    $screen = [];
    $all = [];
    foreach ($processOutput as $line) {
        $line = trim((string) $line);
        if ($line === '' || strpos($line, 'rtorrentExecute.php') === false) {
            continue;
        }
        if (!preg_match('/^(\\d+)\\s+(\\S+)\\s+(.+)$/', $line, $m)) {
            continue;
        }
        $pid = (int) $m[1];
        if ($pid <= 0) {
            continue;
        }
        $comm = strtolower((string) $m[2]);
        $all[] = $pid;
        // comm may show 'php' or script name '.rtorrentexecute' (lowercased)
        if (strpos($comm, 'php') === 0 || strpos($comm, '.rtorrentexecute') === 0) {
            $php[] = $pid;
        } elseif (strpos($comm, 'screen') !== false) {
            $screen[] = $pid;
        }
    }
    return ['php' => $php, 'screen' => $screen, 'all' => $all];
}

/**
 * Capture a process snapshot for diagnostic logging.
 *
 * Returns formatted lines showing PID, PPID, state, start time, and command
 * for all processes owned by the specified user.
 *
 * @param string $user System username.
 *
 * @return string[] Process listing lines.
 */
function rtorrentProcessSnapshot(string $user): array
{
    $out = [];
    $rc = 0;
    @exec(
        'ps -u '.escapeshellarg($user).' -o pid=,ppid=,stat=,lstart=,comm=,args=',
        $out,
        $rc
    );
    if ($rc !== 0) {
        return ['[WARN] ps failed (rc='.$rc.')'];
    }
    $lines = [];
    foreach ($out as $line) {
        $line = trim((string) $line);
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    return $lines;
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
    if (!preg_match('/^(\d+)\s+(\S+)(?:\s+(\S+))?$/', $line, $m)) {
        return null;
    }

    $pid = (int) $m[1];
    if ($pid <= 0) {
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
 * Capture process STAT/WCHAN details for known PIDs.
 *
 * @param int[] $pids Process IDs to inspect.
 *
 * @return array<int, array{pid:int,stat:string,wchan:string}> Process states keyed by PID.
 */
function rtorrentProcessStatesForPids(array $pids): array
{
    $normalized = rtorrentProcessNormalizePids($pids);
    if (empty($normalized)) {
        return [];
    }

    $out = [];
    $rc = 1;
    @exec('ps -o pid=,stat=,wchan= -p '.escapeshellarg(implode(',', $normalized)), $out, $rc);
    if ($rc !== 0) {
        return [];
    }

    return rtorrentProcessStatesFromPsLines($out);
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
