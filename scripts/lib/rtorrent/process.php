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

require_once __DIR__.'/../pathSafety.php';
require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/../user/watchdog.php';
require_once __DIR__.'/legacyDirectives.php';

// Signal constants for systems without pcntl.
if (!defined('SIGTERM')) {
    define('SIGTERM', 15);
}
if (!defined('SIGKILL')) {
    define('SIGKILL', 9);
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
 * Parse one `ps -o pid=,stat=,wchan=` row for restart-safety checks.
 *
 * @param string $line One trimmed or untrimmed ps output row.
 *
 * @return array{pid:int,stat:string,wchan:string}|null Parsed process state.
 */
function rtorrentProcessStateFromPsLine(string $line): ?array
{
    $line = trim($line);
    if ($line === '' || !preg_match('/^(\d+)\s+(\S+)(?:\s+(\S+))?$/', $line, $m)) {
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
        if ($state !== null) {
            $states[$state['pid']] = $state;
        }
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
        if (strpos((string) ($state['stat'] ?? ''), 'D') !== false) {
            return true;
        }
    }

    return false;
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
    return pmssPathTargetIsSafe($stateFile, false, true, false);
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
 * Validate a destructive per-user rTorrent target before moving or creating it.
 *
 * Production callers must stay inside the canonical /home/<user> tree. Tests
 * may use temporary homes, but still exercise absolute path and symlink guards.
 */
function rtorrentProcessUserTargetPathIsSafe(
    string $home,
    string $user,
    string $relativePath,
    bool $directoryTarget,
    bool $requireUserTailInTest = false
): bool {
    $home = rtrim($home, '/');
    $testMode = pmssTestModeEnabled();
    if ((!$testMode && !pmssValidateUsername($user))
        || $home === ''
        || $home[0] !== '/'
        || strpos($home, "\0") !== false
        || !is_dir($home)
        || is_link($home)
        || !pmssPathRelativeStringIsSafe($relativePath, ['allowControlChars' => true])
    ) {
        return false;
    }

    if (!$testMode && $home !== '/home/'.$user) {
        return false;
    }
    if ($testMode && $requireUserTailInTest && substr($home, -strlen('/'.$user)) !== '/'.$user) {
        return false;
    }

    $target = $home.'/'.$relativePath;
    return strpos($target, $home.'/') === 0
        && pmssPathTargetIsSafe($target, $directoryTarget, false, false);
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
    $testMode = pmssTestModeEnabled();

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
    if (!rtorrentProcessUserTargetPathIsSafe($home, $user, 'session', true, true)) {
        $logFn("Refusing to reset unsafe session directory: {$sessionDir}", true);
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

    $matches = [];
    foreach (pmssRtorrentLegacyDirectiveNames() as $label) {
        $pattern = '/^\s*'.preg_quote($label, '/').'\s*=/m';
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
    if (!rtorrentProcessUserTargetPathIsSafe($home, $user, '.rtorrent.rc.custom', false)) {
        $logFn("Refusing to quarantine unsafe custom rTorrent config: {$src}", true);
        return null;
    }
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
    $rc = 0;
    @passthru('/scripts/startRtorrent '.escapeshellarg($user), $rc);
    $logFn("startRtorrent {$user} completed (rc={$rc})", true);

    $now = (string) time();
    rtorrentProcessWriteStateFile('/tmp/.pmss-rtorrent-restart-'.$user, $now);
    if ($startMarkerState !== null && $startMarkerState !== '') rtorrentProcessWriteStateFile($startMarkerState, $now);

    return $rc;
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
    $rtorrentPids = pmssUserWatchdogProcessPids($user, '^rtorrent');
    $executorPids = rtorrentProcessExecutorPids($user)['all'];

    // Force kill.
    rtorrentProcessKillPids(array_merge($rtorrentPids, $executorPids), SIGKILL);
    sleep(1);

    $rc = rtorrentProcessStart($user, $logFn);

    // Capture after snapshot.
    $after = rtorrentProcessSnapshot($user);
    $logFn("Process snapshot AFTER ({$user})", true);
    foreach ($after as $row) {
        $logFn($row, true);
    }

    return $rc;
}
