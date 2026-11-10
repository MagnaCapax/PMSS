<?php
/**
 * Process discovery, snapshots, and stability checks for rTorrent.
 *
 * @license Proprietary
 */

require_once __DIR__.'/processRows.php';
require_once __DIR__.'/processReadiness.php';

/** Capture ps once; failed probes retain raw diagnostics by reference but supply no rows to decoders. */
function rtorrentProcessPsOutput(string $arguments, ?int &$rc = null, ?array &$raw = null): array
{
    $output = [];
    $status = 0;
    @exec('ps '.$arguments, $output, $status);
    $raw = $output;
    $rc = $status;
    return $status === 0 ? $output : [];
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
    return rtorrentProcessExecutorPidsFromPsLines(rtorrentProcessPsOutput(
        '-u '.escapeshellarg($user).' -o pid=,comm=,args=', $rc, $output
    ));
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
    $out = rtorrentProcessPsOutput('-u '.escapeshellarg($user).' -o pid=,ppid=,stat=,lstart=,comm=,args=', $rc);
    return $rc !== 0 ? ['[WARN] ps failed (rc='.$rc.')']
        : iterator_to_array(pmssConfigLines($out, []), false);
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

    return rtorrentProcessStatesFromPsLines(rtorrentProcessPsOutput(
        '-o pid=,stat=,wchan= -p '.escapeshellarg(implode(',', $normalized))
    ));
}
