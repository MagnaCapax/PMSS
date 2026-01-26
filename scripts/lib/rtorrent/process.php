<?php
/**
 * rTorrent process management helpers.
 *
 * Provides utilities for detecting, monitoring, and managing rTorrent and
 * executor processes. Used by cron watchdogs and maintenance scripts.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 * @license   Proprietary
 */

// Signal constants for systems without pcntl.
if (!defined('SIGTERM')) {
    define('SIGTERM', 15);
}
if (!defined('SIGKILL')) {
    define('SIGKILL', 9);
}

/**
 * List PIDs for a user's process by exact command name.
 *
 * Uses pgrep with -x (exact match) to avoid matching substrings.
 *
 * @param string $user System username.
 * @param string $comm Process command name to match.
 *
 * @return int[] Array of matching PIDs.
 */
function rtorrentProcessPgrepExact(string $user, string $comm): array
{
    $out = [];
    $rc = 1;
    @exec('pgrep -u '.escapeshellarg($user).' -x '.escapeshellarg($comm), $out, $rc);
    if ($rc !== 0) {
        return [];
    }
    $pids = [];
    foreach ($out as $line) {
        $pid = (int) trim((string) $line);
        if ($pid > 0) {
            $pids[] = $pid;
        }
    }
    return $pids;
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
function rtorrentProcessExecutorPids(string $user): array
{
    $out = [];
    $rc = 0;
    @exec('ps -u '.escapeshellarg($user).' -o pid=,comm=,args=', $out, $rc);
    if ($rc !== 0) {
        return ['php' => [], 'screen' => [], 'all' => []];
    }

    $php = [];
    $screen = [];
    $all = [];
    foreach ($out as $line) {
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
        if (strpos($comm, 'php') === 0) {
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
 * Send a signal to multiple PIDs.
 *
 * Best-effort delivery - errors are silently ignored. Uses posix_kill when
 * available, falls back to exec kill.
 *
 * @param int[] $pids   PIDs to signal.
 * @param int   $signal Signal number (SIGTERM=15, SIGKILL=9).
 *
 * @return void
 */
function rtorrentProcessKillPids(array $pids, int $signal): void
{
    foreach ($pids as $pid) {
        $pid = (int) $pid;
        if ($pid <= 0) {
            continue;
        }
        if (function_exists('posix_kill')) {
            @posix_kill($pid, $signal);
        } else {
            @exec('kill -'.(int) $signal.' '.(int) $pid);
        }
    }
}

/**
 * Check and track a stale condition using a state file.
 *
 * Implements the pattern: first detection records timestamp, subsequent checks
 * compare age against threshold. Returns the action to take.
 *
 * @param string $stateFile     Path to the state file (stores first-seen timestamp).
 * @param int    $gracePeriod   Seconds to wait before declaring stale.
 *
 * @return array{action:string,age:int} 'action' is 'record'|'wait'|'stale', 'age' is seconds since first seen.
 */
function rtorrentProcessCheckStaleState(string $stateFile, int $gracePeriod): array
{
    $now = time();
    $firstSeen = 0;

    if (is_file($stateFile)) {
        $firstSeen = (int) trim((string) @file_get_contents($stateFile));
    }

    // First time seeing this condition.
    if ($firstSeen <= 0) {
        @file_put_contents($stateFile, (string) $now, LOCK_EX);
        return ['action' => 'record', 'age' => 0];
    }

    $age = $now - $firstSeen;

    // Still within grace period.
    if ($age < $gracePeriod) {
        return ['action' => 'wait', 'age' => $age];
    }

    // Grace period exceeded.
    return ['action' => 'stale', 'age' => $age];
}

/**
 * Clear a stale state file.
 *
 * @param string $stateFile Path to the state file.
 *
 * @return void
 */
function rtorrentProcessClearStaleState(string $stateFile): void
{
    if (is_file($stateFile)) {
        @unlink($stateFile);
    }
}

/**
 * Restart rTorrent for a user with full diagnostic logging.
 *
 * Performs graceful shutdown (SIGTERM), waits, then force kills (SIGKILL),
 * and starts the rTorrent executor. Captures before/after process snapshots.
 *
 * @param string   $user           System username.
 * @param int[]    $rtorrentPids   Current rtorrent PIDs.
 * @param int[]    $executorPids   Current executor PIDs.
 * @param callable $logFn          Logging callback: function(string $message, bool $force).
 * @param bool     $debug          Enable verbose logging.
 *
 * @return int Exit code from startRtorrent.
 */
function rtorrentProcessRestart(
    string $user,
    array $rtorrentPids,
    array $executorPids,
    callable $logFn,
    bool $debug = false
): int {
    // Capture before snapshot.
    $before = rtorrentProcessSnapshot($user);
    $logFn("Process snapshot BEFORE ({$user})", true);
    foreach ($before as $row) {
        $logFn($row, true);
    }

    // Graceful shutdown.
    rtorrentProcessKillPids(array_merge($rtorrentPids, $executorPids), SIGTERM);
    sleep(3);

    // Re-check for survivors.
    $rtorrentPids = rtorrentProcessPgrepExact($user, 'rtorrent');
    $executorPids = rtorrentProcessExecutorPids($user)['all'];

    // Force kill.
    rtorrentProcessKillPids(array_merge($rtorrentPids, $executorPids), SIGKILL);
    sleep(1);

    // Start fresh.
    $rc = 0;
    @passthru('/scripts/startRtorrent '.escapeshellarg($user), $rc);
    $logFn("startRtorrent {$user} completed (rc={$rc})", true);

    // Capture after snapshot.
    $after = rtorrentProcessSnapshot($user);
    $logFn("Process snapshot AFTER ({$user})", true);
    foreach ($after as $row) {
        $logFn($row, true);
    }

    return $rc;
}
