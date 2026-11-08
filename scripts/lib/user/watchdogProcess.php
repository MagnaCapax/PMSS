<?php
/** Process observations and guarded actions for user watchdogs. @license GPL-3.0-only */

/** @param array<int,string> $processNames */
function pmssUserWatchdogTerminateProcesses(string $username, array $processNames, int $signal = 9): void
{
    if (!pmssValidateUsername($username)) {
        return;
    }

    $signal = $signal === 15 ? 15 : 9;
    foreach ($processNames as $processName) {
        if (!is_string($processName) || $processName === '' || $processName[0] === '-' || !pmssCommandBinaryNameIsSafe($processName)) {
            continue;
        }
        @passthru(pmssBuildCommand('killall', ['-'.$signal, '-u', $username, '--', $processName]).' 2>/dev/null');
    }
}

/** @return int[] Normalized positive PIDs; malformed patterns use the invalid-request result. */
function pmssUserWatchdogProcessPids(string $username, string $processPattern, array $options = array(), ?int &$exitCode = null, ?array &$output = null): array
{
    // Reject options and NUL before pgrep can reinterpret the selector or shell quoting can throw.
    if (!pmssValidateUsername($username) || $processPattern === '' || $processPattern[0] === '-'
        || strpos($processPattern, "\0") !== false) {
        $exitCode = 1;
        $output = array();
        return array();
    }

    $command = 'pgrep -u '.escapeshellarg($username)
        .(!empty($options['full']) ? ' -f' : '')
        .(!empty($options['exact']) ? ' -x' : '')
        .' '.escapeshellarg($processPattern)
        .(!empty($options['captureStderr']) ? ' 2>&1' : ' 2>/dev/null');

    $matches = array();
    $rc = 1;
    @exec($command, $matches, $rc);
    $exitCode = $rc;
    $output = $matches;

    $pids = array();
    foreach ($matches as $rawPid) {
        // Parse before selecting a process target; zero is not a PID.
        $pid = pmssUnsignedDecimalIntParse(trim((string) $rawPid));
        if ($pid !== null && $pid > 0) $pids[$pid] = $pid;
    }
    return array_values($pids);
}

/** Require both a successful pgrep exit and at least one normalized PID. */
function pmssUserWatchdogProcessRunning(string $username, string $processName): bool
{
    return pmssUserWatchdogProcessPids($username, $processName, array(), $exitCode) !== array() && $exitCode === 0;
}

/** Acquire a watchdog lock without leaking its descriptor into service daemons. */
function pmssUserWatchdogLockAcquire(string $path)
{
    $handle = pmssLockFileAcquire($path, true);
    if ($handle !== false) {
        pmssLockHandleExportChildCloseFds($handle);
    }
    return $handle;
}

/** Return the oldest start time (Unix seconds) among exact process-name matches. */
function pmssUserWatchdogProcessStartTime(string $username, string $processName, string $procRoot = '/proc'): ?int
{
    $pids = pmssUserWatchdogProcessPids($username, $processName, array('exact' => true), $exitCode);
    if ($exitCode !== 0 || $pids === array()) {
        return null;
    }

    $oldest = null;
    $procRoot = rtrim($procRoot, '/');
    foreach ($pids as $pid) {
        $start = pmssUserWatchdogProcStartTime($procRoot, (string) $pid);
        if ($start !== null) $oldest = $oldest === null ? $start : min($oldest, $start);
    }

    return $oldest;
}

/**
 * Kernel start time of one process: boot time plus /proc/<pid>/stat field 22 (clock ticks, USER_HZ 100).
 * The /proc/<pid> inode time is set when the inode is created, which can be long after the process
 * started and equal to the mtime of a config written in the same second, so a changed proxy fragment
 * never read as newer and lighttpd kept the old rule (Refs #1241). Falls back to that inode time only
 * when the stat files cannot be parsed.
 */
function pmssUserWatchdogProcStartTime(string $procRoot, string $pid): ?int
{
    // Reject malformed paths and non-process identifiers before touching the filesystem.
    if (strpos($procRoot, "\0") !== false || (pmssUnsignedDecimalIntParse($pid) ?? 0) < 1) return null;
    $boot = preg_match('/^btime\s+(\d+)$/m', (string) @file_get_contents($procRoot.'/stat'), $m) === 1
        ? pmssUnsignedDecimalIntParse($m[1]) : null;
    $stat = (string) @file_get_contents($procRoot.'/'.$pid.'/stat');
    $close = strrpos($stat, ')');
    // Fields after the parenthesised command name start at field 3, so field 22 is index 19.
    $fields = $close === false ? array() : preg_split('/\s+/', trim(substr($stat, $close + 1)));
    $ticks = isset($fields[19]) ? pmssUnsignedDecimalIntParse($fields[19]) : null;
    $seconds = $ticks === null ? null : intdiv($ticks, 100);
    // Unrepresentable kernel fields or sums retain the existing inode-time fallback.
    if ($boot !== null && $seconds !== null && $boot <= PHP_INT_MAX - $seconds) {
        return $boot + $seconds;
    }
    $mtime = @filemtime($procRoot.'/'.$pid);
    return is_int($mtime) ? $mtime : null;
}

/** @param array<int,string> $processNames */
function pmssUserWatchdogRestartProcessesIf(string $username, bool $running, array $processNames, callable $restartNeeded, string $userLogMessage, int $signal = 9, ?callable $terminator = null): bool
{
    if (!pmssValidateUsername($username) || !$running || !$restartNeeded()) {
        return $running;
    }

    $terminator !== null ? $terminator() : pmssUserWatchdogTerminateProcesses($username, $processNames, $signal);
    pmssUserLog($username, $userLogMessage);
    return false;
}

/** Apply daemon-owned config only while stopped so live daemons cannot clobber it on exit. */
function pmssUserWatchdogApplyManagedConfigWhenStopped(string $username, bool $running, $configApplier): bool
{
    return pmssValidateUsername($username) && !$running && is_callable($configApplier)
        && (bool) $configApplier($username);
}
