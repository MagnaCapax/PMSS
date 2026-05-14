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

require_once __DIR__.'/../runtime.php';

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
function rtorrentProcessPgrepExact(string $user, string $comm, ?int &$rc = null, ?array &$output = null): array
{
    $pgrepOutput = [];
    $pgrepRc = 1;
    @exec('pgrep -u '.escapeshellarg($user).' '.escapeshellarg('^'.$comm), $pgrepOutput, $pgrepRc);
    $output = $pgrepOutput;
    $rc = $pgrepRc;
    if ($pgrepRc !== 0) {
        return [];
    }
    return rtorrentProcessNormalizePids($pgrepOutput);
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
        $pid = (int) $pid;
        if ($pid > 0) {
            $normalized[$pid] = $pid;
        }
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
 * Validate a state marker path before writing or removing it.
 *
 * State files are operator-visible guardrails for watchdog recovery. Treat the
 * path itself as untrusted so malformed usernames or partial-update callers
 * cannot redirect writes through relative paths, dot segments, or symlinks.
 *
 * @param string $stateFile Candidate state marker path.
 *
 * @return bool True when the target can safely be treated as a regular file.
 */
function rtorrentProcessStateFilePathIsSafe(string $stateFile): bool
{
    $stateFile = rtrim($stateFile, '/');
    if ($stateFile === '' || $stateFile[0] !== '/' || strpos($stateFile, "\0") !== false) {
        return false;
    }

    $segments = explode('/', ltrim($stateFile, '/'));
    $current = '';
    $lastIndex = count($segments) - 1;
    foreach ($segments as $index => $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return false;
        }

        $current .= '/'.$segment;
        if (is_link($current)) {
            return false;
        }

        $isLeaf = $index === $lastIndex;
        if (!$isLeaf && file_exists($current) && !is_dir($current)) {
            return false;
        }
        if ($isLeaf && file_exists($current) && !is_file($current)) {
            return false;
        }
    }

    $parent = dirname($stateFile);
    return is_dir($parent) && !is_link($parent);
}

/**
 * Write a watchdog state marker after validating the target path.
 *
 * @param string $stateFile Candidate state marker path.
 * @param string $payload   Marker payload.
 *
 * @return bool True when the marker was written.
 */
function rtorrentProcessWriteStateFile(string $stateFile, string $payload): bool
{
    return rtorrentProcessStateFilePathIsSafe($stateFile)
        && @file_put_contents($stateFile, $payload, LOCK_EX) !== false;
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
    if (!rtorrentProcessStateFilePathIsSafe($stateFile)) {
        return ['action' => 'record', 'age' => 0];
    }

    $firstSeen = pmssReadRegularFileInt($stateFile);

    // First time seeing this condition.
    if ($firstSeen <= 0) {
        rtorrentProcessWriteStateFile($stateFile, (string) $now);
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
    if (!rtorrentProcessStateFilePathIsSafe($stateFile)) {
        return ['action' => 'record', 'count' => 1];
    }

    $count = max(0, pmssReadRegularFileInt($stateFile));
    $count++;
    rtorrentProcessWriteStateFile($stateFile, (string) $count);

    if ($count >= $failureThreshold) {
        return ['action' => 'stale', 'count' => $count];
    }

    return ['action' => $count === 1 ? 'record' : 'wait', 'count' => $count];
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
    if (rtorrentProcessStateFilePathIsSafe($stateFile) && is_file($stateFile)) { @unlink($stateFile); }
}

/**
 * Reset a user's rTorrent session directory conservatively.
 *
 * Persistent start failures can leave the session state corrupted. Move the
 * current directory aside for later inspection, recreate an empty session
 * directory, and restore expected ownership. The caller decides when this
 * recovery step is justified.
 *
 * @param string   $home   User home directory path.
 * @param string   $user   Username.
 * @param callable $logFn  Logging callback: function(string $message, bool $force).
 *
 * @return bool True when the session directory is ready for reuse.
 */
function rtorrentProcessResetSessionDirectory(string $home, string $user, callable $logFn): bool
{
    $home = rtrim($home, '/');
    $sessionDir = $home.'/session';
    $expected = '/home/'.$user.'/session';
    $testMode = defined('PMSS_TEST_MODE') || getenv('PMSS_TEST_MODE') === '1';

    if ((!$testMode && ($sessionDir !== $expected || strpos($sessionDir, '/home/') !== 0))
        || ($testMode && substr($sessionDir, -strlen('/'.$user.'/session')) !== '/'.$user.'/session')
    ) {
        $logFn("Refusing to reset unexpected session directory: {$sessionDir}", true);
        return false;
    }
    if (is_link($sessionDir)) {
        $logFn("Refusing to reset symlinked session directory: {$sessionDir}", true);
        return false;
    }

    if (is_dir($sessionDir)) {
        $backup = $sessionDir.'.broken-'.date('Ymd_His').'-'.(int) getmypid();
        if (!@rename($sessionDir, $backup)) {
            $logFn("Failed to quarantine broken session directory: {$sessionDir}", true);
            return false;
        }
        $logFn("Quarantined broken session directory: {$sessionDir} -> {$backup}", true);
    }

    if (!pmssDirEnsureExists($sessionDir, 0755)) {
        $logFn("Failed to recreate session directory: {$sessionDir}", true);
        return false;
    }

    @chown($sessionDir, $user);
    if (function_exists('posix_getpwnam')) {
        $pw = @posix_getpwnam($user);
        if (is_array($pw) && isset($pw['gid'])) {
            @chgrp($sessionDir, (int) $pw['gid']);
        }
    }

    return true;
}

/**
 * Find legacy custom-config directives that PMSS templates already migrated away from.
 *
 * Customer overrides are imported verbatim via `try_import`, so startup failures can
 * be harder to triage when the file still carries historic aliases that PMSS no longer
 * ships in its managed template. Return the matched directive names in a stable order
 * so callers can emit actionable diagnostics before quarantining the file.
 *
 * @param string $content Raw `.rtorrent.rc.custom` contents.
 *
 * @return string[] Legacy directive labels found in the config.
 */
function rtorrentCustomConfigFindLegacyDirectives(string $content): array
{
    if ($content === '') {
        return [];
    }

    $patterns = [
        'tracker_numwant' => '/^\s*tracker_numwant\s*=/m',
        'use_udp_trackers' => '/^\s*use_udp_trackers\s*=/m',
        'port_range' => '/^\s*port_range\s*=/m',
        'check_hash' => '/^\s*check_hash\s*=/m',
        'schedule' => '/^\s*schedule\s*=/m',
        'schedule_remove' => '/^\s*schedule_remove\s*=/m',
        'load_start' => '/^\s*load_start\s*=/m',
        'load_start_verbose' => '/^\s*load_start_verbose\s*=/m',
        'execute' => '/^\s*execute\s*=/m',
        'umask' => '/^\s*umask\s*=/m',
        'hash_interval' => '/^\s*hash_interval\s*=/m',
        'hash_max_tries' => '/^\s*hash_max_tries\s*=/m',
    ];

    $matches = [];
    foreach ($patterns as $label => $pattern) {
        if (preg_match($pattern, $content) === 1) {
            $matches[] = $label;
        }
    }

    return $matches;
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

    $content = @file_get_contents($src);
    if (is_string($content)) {
        $legacyDirectives = rtorrentCustomConfigFindLegacyDirectives($content);
        if ($legacyDirectives !== []) {
            $logFn(
                'Custom rTorrent config still uses legacy PMSS-migrated directives: '
                .implode(', ', $legacyDirectives),
                true
            );
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
    rtorrentProcessWriteStateFile('/tmp/.pmss-rtorrent-restart-'.$user, (string) time());

    // Capture after snapshot.
    $after = rtorrentProcessSnapshot($user);
    $logFn("Process snapshot AFTER ({$user})", true);
    foreach ($after as $row) {
        $logFn($row, true);
    }

    return $rc;
}
