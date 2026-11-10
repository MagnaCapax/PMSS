<?php
/**
 * rTorrent signals and process lifecycle; watchdog decisions live in watchdogScgi.php.
 *
 * @license Proprietary
 */

require_once __DIR__.'/watchdogScgi.php';
require_once __DIR__.'/processReadiness.php';

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
        // Validate at the signal boundary even when callers already normalized.
        $pid = rtorrentProcessPidParse($pid);
        if ($pid === null) {
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
 * Resolve and signal the rTorrent processes managed for one user.
 *
 * @param string $user   System username.
 * @param int    $signal Signal number (SIGTERM=15, SIGKILL=9).
 *
 * @return int Number of unique process IDs targeted.
 */
function rtorrentProcessKillManagedPids(string $user, int $signal): int
{
    if (!pmssValidateUsername($user)) {
        return 0;
    }

    $rtorrentPids = pmssUserWatchdogProcessPids($user, '^rtorrent');
    $executorPids = rtorrentProcessExecutorPids($user)['all'];
    $managedPids = rtorrentProcessNormalizePids(array_merge($rtorrentPids, $executorPids));
    rtorrentProcessKillPids($managedPids, $signal);

    return count($managedPids);
}

/**
 * Start rTorrent for a user and refresh restart tracking markers.
 *
 * @param string      $user             System username.
 * @param callable    $logFn            Logging callback: function(string $message, bool $force).
 * @param string|null $startMarkerState Optional watchdog marker for a direct start attempt.
 *
 * @return int Exit code from startRtorrent.
 */
function rtorrentProcessStart(string $user, callable $logFn, ?string $startMarkerState = null): int
{
    if (!pmssValidateUsername($user)) {
        $logFn('Refusing to start rTorrent for invalid username', true);
        return 1;
    }

    if (pmssUserWatchdogBackoffActive($user, 'rtorrent')) return 1;
    $result = pmssUserWatchdogCommandRun('/scripts/startRtorrent '.escapeshellarg($user), 330, $user, 'rtorrent');
    $rc = $result['rc'];
    $logFn("startRtorrent {$user} completed (rc={$rc})", true);

    $now = (string) time();
    rtorrentProcessWriteStateFile(rtorrentProcessRestartMarkerPath($user), $now);
    if ($startMarkerState !== null && $startMarkerState !== '') rtorrentProcessWriteStateFile($startMarkerState, $now);

    return $rc;
}

/** Emit a process snapshot with the legacy heading and row callback sequence. */
function rtorrentProcessLogSnapshot(string $user, string $phase, callable $logFn): void
{
    $rows = rtorrentProcessSnapshot($user);
    $logFn("Process snapshot {$phase} ({$user})", true);
    foreach ($rows as $row) $logFn($row, true);
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
    if (!pmssValidateUsername($user)) {
        $logFn('Refusing to restart rTorrent for invalid username', true);
        return 1;
    }

    rtorrentProcessLogSnapshot($user, 'BEFORE', $logFn);

    // Graceful shutdown.
    rtorrentProcessKillPids(array_merge($rtorrentPids, $executorPids), SIGTERM);
    sleep(3);

    // Re-check for survivors.
    $rtorrentPids = pmssUserWatchdogProcessPids($user, '^rtorrent');
    $executorPids = rtorrentProcessExecutorPids($user)['all'];

    // Force kill.
    rtorrentProcessKillPids(array_merge($rtorrentPids, $executorPids), SIGKILL);
    sleep(1);

    $rc = rtorrentProcessStart($user, $logFn);

    rtorrentProcessLogSnapshot($user, 'AFTER', $logFn);

    return $rc;
}
