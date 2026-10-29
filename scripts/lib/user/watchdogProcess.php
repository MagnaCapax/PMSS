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
    // Reject NUL before shell quoting can throw or truncate the process selector.
    if (!pmssValidateUsername($username) || $processPattern === '' || strpos($processPattern, "\0") !== false) {
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

/** Return the oldest /proc start marker for exact process-name matches. */
function pmssUserWatchdogProcessStartTime(string $username, string $processName, string $procRoot = '/proc'): ?int
{
    $pids = pmssUserWatchdogProcessPids($username, $processName, array('exact' => true), $exitCode);
    if ($exitCode !== 0 || $pids === array()) {
        return null;
    }

    $oldest = null;
    $procRoot = rtrim($procRoot, '/');
    foreach ($pids as $pid) {
        $mtime = @filemtime($procRoot.'/'.$pid);
        if (is_int($mtime)) $oldest = $oldest === null ? $mtime : min($oldest, $mtime);
    }

    return $oldest;
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
