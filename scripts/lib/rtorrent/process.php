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
 * List PIDs for a user's process by command name.
 *
 * Uses pgrep with start-anchored pattern. Note: rtorrent may report as
 * "rtorrent main" on some systems, so we use ^pattern to match process
 * names starting with the given string (avoids matching .rtorrentExecute).
 *
 * @param string $user System username.
 * @param string $comm Process command name (start-anchored pgrep pattern).
 *
 * @return int[] Array of matching PIDs.
 */
function rtorrentProcessPgrepExact(string $user, string $comm): array
{
    $out = [];
    $rc = 1;
    @exec('pgrep -u '.escapeshellarg($user).' '.escapeshellarg('^'.$comm), $out, $rc);
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

    return ['action' => $age < $gracePeriod ? 'wait' : 'stale', 'age' => $age];
}

/**
 * Check and track consecutive failed start attempts using a counter file.
 *
 * Stores a single integer count in the state file. First failure records 1 and
 * returns `record`, intermediate counts return `wait`, and reaching the
 * threshold returns `stale` so callers can escalate.
 *
 * @param string $stateFile         Path to the state file.
 * @param int    $failureThreshold  Number of failed attempts before escalation.
 *
 * @return array{action:string,count:int} 'action' is 'record'|'wait'|'stale', 'count' is the current failed-attempt count.
 */
function rtorrentProcessCheckFailureCountState(string $stateFile, int $failureThreshold): array
{
    $failureThreshold = max(1, $failureThreshold);
    $count = 0;

    if (is_file($stateFile)) {
        $count = (int) trim((string) @file_get_contents($stateFile));
    }

    if ($count < 0) {
        $count = 0;
    }

    $count++;
    @file_put_contents($stateFile, (string) $count, LOCK_EX);

    if ($count < $failureThreshold) {
        return ['action' => $count === 1 ? 'record' : 'wait', 'count' => $count];
    }

    return ['action' => 'stale', 'count' => $count];
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
 * Quarantine a user's custom rTorrent config file.
 *
 * When /home/<user>/.rtorrent.rc.custom exists but is invalid, rTorrent can
 * fail to start because the main template uses `try_import`. This helper moves
 * the custom file aside (atomic rename) so the instance can start with the
 * baseline config while preserving the customer's file for support review.
 *
 * Safety guards:
 * - refuses to touch symlinks
 * - refuses to move files not owned by root or the user (best-effort)
 *
 * @param string   $home   User home directory path.
 * @param string   $user   Username.
 * @param callable $logFn  Logging callback: function(string $message, bool $force).
 *
 * @return string|null Destination path when quarantined, null otherwise.
 */
function rtorrentCustomConfigQuarantine(string $home, string $user, callable $logFn): ?string
{
    $home = rtrim($home, '/');
    $src = $home.'/.rtorrent.rc.custom';
    if (!is_file($src) || is_link($src)) {
        return null;
    }

    // Best-effort ownership guard: only allow root-owned or user-owned files.
    if (function_exists('posix_getpwnam')) {
        $pw = @posix_getpwnam($user);
        $uid = (is_array($pw) && isset($pw['uid'])) ? (int) $pw['uid'] : null;
        $owner = @fileowner($src);
        if ($owner !== false && $uid !== null && (int) $owner !== 0 && (int) $owner !== $uid) {
            $logFn("Refusing to quarantine {$src}: unexpected owner uid={$owner}", true);
            return null;
        }
    }

    $timestamp = date('Ymd_His');
    $suffix = $timestamp.'-'.(int) getmypid();
    $dst = $src.'.broken-'.$suffix;

    if (@rename($src, $dst)) {
        $logFn("Quarantined broken custom rTorrent config: {$src} -> {$dst}", true);
        return $dst;
    }
    $logFn("Failed to quarantine custom rTorrent config: {$src}", true);
    return null;
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
    // crc32 returns signed int, convert to positive
    if ($hash < 0) {
        $hash = $hash & 0x7FFFFFFF;
    }
    return $hash % ($maxDelay + 1);
}

/**
 * Check if rTorrent was recently restarted by watchdog.
 *
 * Uses marker file with timestamp to track restart events. Returns the age
 * in seconds since last restart, or 0 if never restarted.
 *
 * @param string $user Username.
 *
 * @return int Seconds since last restart, or 0 if no restart recorded.
 */
function rtorrentProcessLastRestartAge(string $user): int
{
    $markerFile = '/tmp/.pmss-rtorrent-restart-'.$user;
    if (!is_file($markerFile)) {
        return 0;
    }
    $ts = (int) trim((string) @file_get_contents($markerFile));
    if ($ts <= 0) {
        return 0;
    }
    return max(0, time() - $ts);
}

/**
 * Record a restart event for a user.
 *
 * Writes current timestamp to marker file for restart tracking.
 *
 * @param string $user Username.
 *
 * @return void
 */
function rtorrentProcessRecordRestart(string $user): void
{
    @file_put_contents('/tmp/.pmss-rtorrent-restart-'.$user, (string) time(), LOCK_EX);
}

/**
 * Calculate extended grace period based on recent restart history.
 *
 * Implements progressive backoff: if rtorrent was recently restarted,
 * extend the grace period to allow hash-checking to complete.
 *
 * @param int $baseGrace   Base grace period in seconds.
 * @param int $restartAge  Seconds since last restart.
 *
 * @return int Extended grace period.
 */
function rtorrentProcessExtendedGrace(int $baseGrace, int $restartAge): int
{
    // No recent restart - use base grace.
    if ($restartAge <= 0) {
        return $baseGrace;
    }

    // Restarted within last 2 hours (7200s) - extend to 600s (10 minutes).
    if ($restartAge < 7200) {
        return max($baseGrace, 600);
    }

    // Restarted within last 4 hours (14400s) - extend to 1200s (20 minutes).
    if ($restartAge < 14400) {
        return max($baseGrace, 1200);
    }

    // Old restart - use base grace.
    return $baseGrace;
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

    // Record restart for backoff tracking.
    rtorrentProcessRecordRestart($user);

    // Capture after snapshot.
    $after = rtorrentProcessSnapshot($user);
    $logFn("Process snapshot AFTER ({$user})", true);
    foreach ($after as $row) {
        $logFn($row, true);
    }

    return $rc;
}
