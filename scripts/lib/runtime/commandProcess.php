<?php
/** Shared command process lifecycle; loaded through runtime/commands.php. */

function pmssProcessCloseExitCode($process, $lastStatus = null): int
{
    $observedExitCode = is_array($lastStatus) ? ($lastStatus['exitcode'] ?? null) : null;
    $fallbackExitCode = is_int($observedExitCode) && $observedExitCode >= 0 ? $observedExitCode : null;
    $rc = is_resource($process) ? @proc_close($process) : -1;
    return ($rc === -1 && $fallbackExitCode !== null) ? $fallbackExitCode : (int) $rc;
}

// Inherited-stdio commands still report the same result shape as piped capture.
function pmssCommandInheritedTtyCapture(string $bash, string $timeoutCommand, int $timeoutSec): array
{
    return pmssCommandProcessCapture($bash, $timeoutCommand, $timeoutSec, 0, false, '', 1, true, '', null, null, true);
}

/**
 * @return array{rc:int,stdout:string,stderr:string,timed_out:bool,launch_failed:bool,pipe_failed:bool}
 */
function pmssCommandPipedCapture(string $bash, string $timeoutCommand, int $timeoutSec, int $maxBuffer = 0, bool $mirrorOutput = false, string $launchError = 'proc_open failed', int $launchRc = 1, bool $retryLaunch = false, string $streamSelectError = 'stream_select failed', ?string $cwd = null, ?array $env = null): array
{
    return pmssCommandProcessCapture($bash, $timeoutCommand, $timeoutSec, $maxBuffer, $mirrorOutput, $launchError, $launchRc, $retryLaunch, $streamSelectError, $cwd, $env);
}

/** Launch, collect, and reap both I/O modes through one lifecycle; public wrappers keep their defaults. */
function pmssCommandProcessCapture(string $bash, string $timeoutCommand, int $timeoutSec, int $maxBuffer, bool $mirrorOutput, string $launchError, int $launchRc, bool $retryLaunch, string $streamSelectError, ?string $cwd, ?array $env, bool $inheritTty = false): array
{
    if ($cwd !== null && ($cwd === '' || pmssFilesystemPathHasNulByte($cwd) || !is_dir($cwd))) {
        return ['rc' => $launchRc, 'stdout' => '', 'stderr' => 'unsafe proc_open cwd', 'timed_out' => false, 'launch_failed' => true, 'pipe_failed' => false];
    }
    if ($env !== null) {
        $normalizedEnv = [];
        foreach ($env as $key => $value) {
            if (!is_string($key)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1
                || (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value))
            ) {
                return ['rc' => $launchRc, 'stdout' => '', 'stderr' => 'unsafe proc_open environment', 'timed_out' => false, 'launch_failed' => true, 'pipe_failed' => false];
            }
            $value = (string) $value;
            if (strpos($value, "\0") !== false) {
                return ['rc' => $launchRc, 'stdout' => '', 'stderr' => 'unsafe proc_open environment', 'timed_out' => false, 'launch_failed' => true, 'pipe_failed' => false];
            }
            $normalizedEnv[$key] = $value;
        }
        $env = $normalizedEnv;
    }

    // Every piped command gets its own process group so a timeout reaches daemonizing
    // grandchildren. The inherited-TTY path deliberately does NOT: a separate group is not the
    // terminal's foreground group, so a child reading the TTY would stop on SIGTTIN. That path
    // is operator-supervised, where an orphan is visible; this one is the unattended path.
    $bash = $inheritTty ? $bash : pmssCommandProcessGroupWrap($bash, $timeoutSec);

    $pipes = [];
    $descriptor = $inheritTty ? [0 => STDIN, 1 => STDOUT, 2 => STDERR] : pmssProcessPipeDescriptorSpec();
    for ($attempt = 0; $attempt < ($retryLaunch ? 2 : 1); $attempt++) {
        if ($attempt > 0) usleep(500000);
        // Inherited-terminal launch warnings remain visible as before.
        $process = $inheritTty ? proc_open($bash, $descriptor, $pipes) : @proc_open($bash, $descriptor, $pipes, $cwd, $env);
        if (is_resource($process)) break;
    }
    if (!is_resource($process)) {
        return ['rc' => $launchRc, 'stdout' => '', 'stderr' => $launchError, 'timed_out' => false, 'launch_failed' => true, 'pipe_failed' => false];
    }
    $pipeFailed = false;
    foreach ($inheritTty ? [] : [0, 1, 2] as $index) {
        if (!isset($pipes[$index]) || !is_resource($pipes[$index])) { $pipeFailed = true; break; }
    }
    if (!$inheritTty && !$pipeFailed) {
        fclose($pipes[0]);
        foreach ([1, 2] as $index) {
            if (!@stream_set_blocking($pipes[$index], false)) { $pipeFailed = true; break; }
        }
    }
    if ($pipeFailed) {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($process)) {
            if (function_exists('proc_terminate')) {
                @proc_terminate($process);
            }
            @proc_close($process);
        }
        return ['rc' => $launchRc, 'stdout' => '', 'stderr' => 'proc_open pipes unavailable', 'timed_out' => false, 'launch_failed' => false, 'pipe_failed' => true];
    }

    $startedAt = microtime(true);
    $lastStatus = null;
    $capture = ['stdout' => '', 'stderr' => '', 'timed_out' => false];
    if ($inheritTty) {
        while (true) {
            $status = proc_get_status($process);
            $lastStatus = is_array($status) ? $status : $lastStatus;
            if (!is_array($status) || empty($status['running'])) break;
            if ($timeoutSec > 0 && (microtime(true) - $startedAt) > $timeoutSec) { $capture['timed_out'] = true; break; }
            usleep(200000);
        }
    } else {
        $capture = pmssCommandOutputPipesDrain($pipes, $timeoutSec, $startedAt, $maxBuffer, $mirrorOutput, $streamSelectError);
        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) fclose($pipes[$index]);
        }
    }
    $rc = $capture['timed_out']
        ? pmssCommandTimeoutClose($process, $timeoutCommand, $timeoutSec, $startedAt)
        : pmssProcessCloseExitCode($process, $lastStatus);

    return ['rc' => $rc] + $capture + ['launch_failed' => false, 'pipe_failed' => false];
}
