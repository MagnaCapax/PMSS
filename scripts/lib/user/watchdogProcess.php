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
        // Reject corruption before trim() can turn it into a usable process target.
        $rawPid = (string) $rawPid;
        if (strpos($rawPid, "\0") !== false) continue;
        $pid = pmssUnsignedDecimalIntParse(trim($rawPid));
        if ($pid !== null && $pid > 0) $pids[$pid] = $pid;
    }
    return array_values($pids);
}

/** Require successful pgrep and a normalized PID that is not a confirmed zombie. */
function pmssUserWatchdogProcessRunning(string $username, string $processName): bool
{
    $pids = pmssUserWatchdogProcessPids($username, $processName, array(), $exitCode);
    if ($exitCode !== 0) return false;
    foreach ($pids as $pid) {
        $status = (string) @file_get_contents('/proc/'.$pid.'/status');
        // pgrep also matches zombies; unknown or corrupt status retains the conservative running result.
        if (strpos($status, "\0") !== false || !preg_match('/^State:\s*Z\b/m', $status)) return true;
    }
    return false;
}

/** Acquire a watchdog lock without leaking its descriptor into service daemons. */
function pmssUserWatchdogLockAcquire(string $path)
{
    $handle = pmssLockFileAcquire($path, true);
    if ($handle !== false) {
        try {
            pmssLockHandleExportChildCloseFds($handle);
        } catch (\Throwable $error) {
            // Ownership transfers only after descriptor export succeeds.
            @fclose($handle);
            throw $error;
        }
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
    $bootRaw = (string) @file_get_contents($procRoot.'/stat');
    // Corruption anywhere in either observation cannot certify a kernel start time.
    $boot = strpos($bootRaw, "\0") === false && preg_match('/^btime[ \t]+(\d+)$/m', $bootRaw, $m) === 1
        ? pmssUnsignedDecimalIntParse($m[1]) : null;
    $stat = (string) @file_get_contents($procRoot.'/'.$pid.'/stat');
    // Require the requested PID and a parenthesised command before trusting the field offsets.
    $close = strpos($stat, "\0") === false && preg_match('/\A([0-9]+) \(/', $stat, $m) === 1
        && pmssUnsignedDecimalIntParse($m[1]) === pmssUnsignedDecimalIntParse($pid)
        ? strrpos($stat, ')') : false;
    // Field 3 must follow ") "; fields after the command put field 22 at index 19.
    $fields = $close === false || substr($stat, $close, 2) !== ') ' ? array() : pmssConfigLineColumns(substr($stat, $close + 1), 0, []);
    $ticks = isset($fields[19]) ? pmssUnsignedDecimalIntParse($fields[19]) : null;
    $seconds = $ticks === null ? null : intdiv($ticks, 100);
    // Unrepresentable kernel fields or sums retain the existing inode-time fallback.
    if ($boot !== null && $seconds !== null && $boot <= PHP_INT_MAX - $seconds) {
        return $boot + $seconds;
    }
    $mtime = @filemtime($procRoot.'/'.$pid);
    return is_int($mtime) ? $mtime : null;
}

/**
 * SIGTERM each process in order, give them up to about ten seconds to exit, then SIGKILL what is left.
 * A daemon killed mid-write leaves its data to be re-checked or lost, so the hard kill is the last step.
 *
 * @param array<int,string> $processNames
 * @param array{terminate?:callable,running?:callable,pause?:callable,attempts?:int} $ops
 */
function pmssUserWatchdogTerminateProcessesWithGrace(string $username, array $processNames, array $ops = []): void
{
    $terminate = $ops['terminate'] ?? 'pmssUserWatchdogTerminateProcesses';
    $running = $ops['running'] ?? 'pmssUserWatchdogProcessRunning';
    $pause = $ops['pause'] ?? static function (): void { usleep(250000); };
    $attempts = $ops['attempts'] ?? 40;
    $terminate($username, $processNames, 15);
    $left = $processNames;
    for ($i = 0; $i <= $attempts; $i++) {
        $left = array_values(array_filter($left, static function ($name) use ($running, $username): bool { return $running($username, $name); }));
        if ($left === [] || $i === $attempts) break;
        $pause();
    }
    if ($left !== []) $terminate($username, $left, 9);
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
