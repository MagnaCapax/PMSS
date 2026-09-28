<?php
/**
 * rTorrent watchdog decisions, signals, and process lifecycle.
 *
 * @license Proprietary
 */

/** Decide what to do with an alive but SCGI-unresponsive process.
 * @return array{action:string,message:string} action is extend_grace|observe_wedge|restart.
 */
function rtorrentProcessScgiUnresponsiveDecision(
    array $rtorrentPids,
    array $processStates,
    ?array $queueSnapshot,
    string $wedgeStateFile,
    int $wedgeCycles
): array {
    $pidsText = implode(',', rtorrentProcessNormalizePids($rtorrentPids));
    if ($processStates === []) {
        return ['action' => 'extend_grace', 'message' => 'SCGI unresponsive but rtorrent process state is unavailable (pids='.$pidsText.'); extending grace'];
    }

    if (rtorrentProcessStatesHaveUninterruptibleIo($processStates)) {
        return ['action' => 'extend_grace', 'message' => 'SCGI unresponsive but rtorrent is in uninterruptible I/O state (pids='.$pidsText.'); extending grace'];
    }

    $queueText = $queueSnapshot === null
        ? 'queue=unavailable'
        : 'recvQ='.(int) $queueSnapshot['recvQ'].' sendQ='.(int) $queueSnapshot['sendQ'];
    if ($queueSnapshot === null || !rtorrentScgiSocketQueueSaturated($queueSnapshot)) {
        return ['action' => 'extend_grace', 'message' => 'SCGI unresponsive but rtorrent still alive (pids='.$pidsText.'; '.$queueText.'); extending grace'];
    }

    $wedgeState = rtorrentProcessCheckFailureCountState($wedgeStateFile, $wedgeCycles);
    if (($wedgeState['action'] ?? '') !== 'stale') {
        return ['action' => 'observe_wedge', 'message' => 'SCGI accept queue saturated for rtorrent (pids='.$pidsText.'; '.$queueText.'; count='.(int) ($wedgeState['count'] ?? 0).'/'.$wedgeCycles.'); observing'];
    }

    return ['action' => 'restart', 'message' => 'SCGI accept queue saturated for consecutive checks (pids='.$pidsText.'; '.$queueText.'); restarting rtorrent'];
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
 * Check if system was recently rebooted.
 *
 * Reads /proc/uptime to determine seconds since boot. Used to detect
 * post-reboot conditions and trigger staggered starts.
 *
 * @param int $threshold Seconds threshold (default: 600 = 10 minutes).
 *
 * @return bool True if uptime is less than threshold.
 */
function rtorrentProcessRecentReboot(int $threshold = 600): bool
{
    $uptime = @file_get_contents('/proc/uptime');
    if ($uptime === false) {
        return false;
    }
    // /proc/uptime format: "12345.67 98765.43" (uptime idle_time)
    $parts = explode(' ', trim($uptime));
    $seconds = (float) $parts[0];
    return $seconds > 0 && $seconds < $threshold;
}

/**
 * Calculate a deterministic delay for a user.
 *
 * Uses crc32 hash of username to generate a consistent but distributed delay.
 * This ensures the same user always gets the same delay, but different users
 * are spread across the time window.
 *
 * @param string $user      Username.
 * @param int    $maxDelay  Maximum delay in seconds (default: 300 = 5 minutes).
 *
 * @return int Delay in seconds (0 to $maxDelay).
 */
function rtorrentProcessStaggerDelay(string $user, int $maxDelay = 300): int
{
    $hash = crc32($user);
    return ($hash < 0 ? $hash & 0x7FFFFFFF : $hash) % ($maxDelay + 1);
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

    $rc = 0;
    @passthru('/scripts/startRtorrent '.escapeshellarg($user), $rc);
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
