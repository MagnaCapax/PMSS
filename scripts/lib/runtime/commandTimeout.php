<?php
/** Command deadlines and process-group termination (ADR 0035). */

function pmssTimeoutFireLog(string $command, int $intendedSeconds, float $actualSeconds, string $signal, int $exitStatus): void
{
    $logPath = getenv('PMSS_TIMEOUT_FIRE_LOG');
    $logPath = is_string($logPath) && trim($logPath) !== '' ? trim($logPath) : PMSS_TIMEOUT_FIRE_LOG_DEFAULT;
    $command = trim((string) preg_replace('/[\r\n\0\t ]+/', ' ', $command));
    $payload = [
        'timestamp'        => date('c'),
        'event'            => 'timeout_fired',
        'command'          => strlen($command) > 500 ? substr($command, 0, 500).'...' : $command,
        'intended_seconds' => $intendedSeconds,
        'actual_seconds'   => (float) sprintf('%.3f', max(0.0, $actualSeconds)),
        'signal'           => $signal,
        'exit_status'      => $exitStatus,
        'correlation_id'   => pmssCorrelationId(),
    ];

    pmssJsonLineAppend($logPath, $payload);
    pmssLogJson($payload);
}

/**
 * Locate coreutils timeout without trusting a PATH lookup inside the generated command.
 *
 * An unresolvable name would make every wrapped command fail as "exec: not found", so the
 * caller degrades to the unwrapped invocation instead when this returns an empty string.
 */
function pmssCommandTimeoutBinaryPath(): string
{
    foreach (['/usr/bin/timeout', '/bin/timeout'] as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }

    return '';
}

/**
 * Run the child in its own process group so a timeout can reach daemonizing grandchildren.
 *
 * `proc_terminate()` signals only the DIRECT child. A grandchild that daemonizes survives,
 * reparents to PID 1 and keeps running with the caller's privilege -- under `update.php` that
 * means root. That is the mechanism behind the 2026-07-03 root compromise, and ADR 0034 records
 * the remedy shape: the command must run in its own process group and the GROUP gets signalled.
 *
 * Coreutils `timeout` provides that group: it calls setpgid() on itself before forking, so it
 * becomes the group leader and signals the whole group. Two details are load-bearing:
 *
 *  - The `exec ` prefix. `proc_open()` launches a string through `/bin/sh -c`, and without
 *    `exec` the direct child is that shell -- still in the CALLER's process group -- leaving
 *    `timeout` one level too deep for the parent to signal.
 *  - `--foreground` must NEVER appear here. It suppresses the setpgid() and the group signal,
 *    i.e. it disables exactly the behaviour this wrapper exists to provide.
 *
 * The coreutils deadline is deliberately LATER than the PHP watchdog's: PHP stays the timeout
 * decider so `timed_out`, the [TIMEOUT] operator line and the timeout-fire JSONL keep firing.
 * Coreutils only reaps the group when the PHP parent itself dies before its own deadline.
 */
function pmssCommandProcessGroupWrap(string $bash, int $timeoutSec): string
{
    // Unbounded must stay unbounded: userTransfer.php runs multi-day migrations with timeout 0.
    if ($timeoutSec <= 0) {
        return $bash;
    }

    $timeoutBinary = pmssCommandTimeoutBinaryPath();
    if ($timeoutBinary === '') {
        // Fail LOUD, never silently. Without coreutils timeout there is no process group, so a
        // daemonizing grandchild survives the timeout exactly as it did before ADR 0035 -- and an
        // unannounced fallback is indistinguishable from the fixed state. Silence is what let the
        // original orphan run as root for 60 days; do not reproduce that property in the fix.
        $warning = 'WARNING: coreutils timeout not found -- command runs WITHOUT process-group '
            .'containment; a daemonizing grandchild can survive its timeout (ADR 0035)';
        // commands.php has no guaranteed log.php in its include chain, and this branch is only
        // reachable when coreutils is missing -- i.e. exactly when an undefined-function fatal
        // would be least welcome. error_log() always exists; logmsg() is used when available.
        function_exists('logmsg') ? logmsg($warning) : error_log($warning);
        return $bash;
    }

    return 'exec '.$timeoutBinary
        .' --kill-after='.PMSS_COMMAND_TIMEOUT_KILL_AFTER_DEFAULT.'s '
        .($timeoutSec + PMSS_COMMAND_TIMEOUT_BACKSTOP_GRACE_SECONDS).'s '
        .$bash;
}

/**
 * Return the direct child's pid when it LEADS its own process group, otherwise 0.
 *
 * This is the safety gate for group signalling. `posix_kill(-$pid, …)` is only safe when the
 * child owns the group; if the child merely inherited the caller's group, the negative pid
 * would signal `update.php` itself and every sibling command it started. Proving leadership
 * with `posix_getpgid($pid) === $pid` makes that outcome unreachable rather than unlikely.
 */
function pmssCommandProcessGroupLeaderPid($process): int
{
    if (!function_exists('posix_kill') || !function_exists('posix_getpgid')) {
        return 0;
    }

    $status = @proc_get_status($process);
    $pid = is_array($status) ? (int) ($status['pid'] ?? 0) : 0;
    if ($pid <= 0) {
        return 0;
    }

    return @posix_getpgid($pid) === $pid ? $pid : 0;
}

/** Signal the child's whole process group when it owns one, otherwise just the child. */
function pmssCommandSignalChildOrGroup($process, int $groupPid, int $signal): void
{
    if ($groupPid > 0 && @posix_kill(-$groupPid, $signal)) {
        return;
    }

    @proc_terminate($process, $signal);
}

function pmssCommandTimeoutTerminate($process, int $killAfterSeconds = PMSS_COMMAND_TIMEOUT_KILL_AFTER_DEFAULT): string
{
    if (!is_resource($process) || !function_exists('proc_terminate')) {
        return 'SIGTERM';
    }

    // Resolve the group before signalling: once the child exits its pgid is no longer readable.
    $groupPid = pmssCommandProcessGroupLeaderPid($process);

    pmssCommandSignalChildOrGroup($process, $groupPid, 15);
    $deadline = microtime(true) + max(0, $killAfterSeconds);
    while (microtime(true) < $deadline) {
        $status = @proc_get_status($process);
        if (!is_array($status) || empty($status['running'])) {
            return 'SIGTERM';
        }
        usleep(100000);
    }

    // Escalate on the GROUP, not just the wrapper: a SIGTERM-ignoring grandchild otherwise
    // outlives the wrapper we kill here and becomes the permanent orphan again.
    pmssCommandSignalChildOrGroup($process, $groupPid, 9);
    return 'SIGKILL';
}

function pmssCommandTimeoutClose($process, string $cmd, int $timeoutSec, float $startedAt): int
{
    $signal = pmssCommandTimeoutTerminate($process);
    @proc_close($process);
    $exitCode = $signal === 'SIGKILL' ? 137 : 124;
    pmssTimeoutFireLog($cmd, $timeoutSec, microtime(true) - $startedAt, $signal, $exitCode);
    return $exitCode;
}

/**
 * Resolve the deadline for any command, regardless of command class.
 *
 * There is deliberately no per-class carve-out. The apt/dpkg branch that used to live here
 * raised the deadline to a constant equal to the default, so it computed max(1200, 1200) and
 * only ever acted as a floor under an env override that LOWERED the value -- a bespoke path
 * for one command class that changed nothing. `$cmd` is kept so callers keep a stable
 * interface and so the "one deadline for every command" invariant stays testable.
 */
function pmssCommandTimeoutSeconds(string $cmd): int
{
    unset($cmd);
    $timeoutEnv = getenv('PMSS_COMMAND_TIMEOUT');

    return ($timeoutEnv !== false && $timeoutEnv !== '' && ctype_digit($timeoutEnv))
        ? (int) $timeoutEnv
        : PMSS_COMMAND_TIMEOUT_DEFAULT;
}
