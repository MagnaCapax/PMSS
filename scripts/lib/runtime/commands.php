<?php
/**
 * Command execution internals for the shared PMSS runtime facade.
 *
 * This file is loaded by `scripts/lib/runtime.php`; callers keep requiring the
 * facade so the command contract remains stable while the large runtime file
 * stays focused on bootstrap and small environment helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

function pmssProcessPipeDescriptorSpec(string $stdinMode = 'r', string $stdoutMode = 'w', string $stderrMode = 'w'): array
{
    return [0 => ['pipe', $stdinMode], 1 => ['pipe', $stdoutMode], 2 => ['pipe', $stderrMode]];
}

/**
 * @return array{stdout:string,stderr:string,timed_out:bool}
 */
function pmssCommandOutputPipesDrain(array $pipes, int $timeoutSec, float $startedAt, int $maxBuffer = 0, bool $mirrorOutput = false, string $streamSelectError = ''): array
{
    $stdout = '';
    $stderr = '';
    $timedOut = false;

    while (!feof($pipes[1]) || !feof($pipes[2])) {
        $read = [];
        foreach ([1, 2] as $index) {
            if (!feof($pipes[$index])) {
                $read[] = $pipes[$index];
            }
        }
        if ($read === []) {
            break;
        }

        $write = $except = [];
        $ready = stream_select($read, $write, $except, 0, 200000);
        if ($ready === false) {
            if ($streamSelectError !== '') {
                $stderr .= ($stderr !== '' ? "\n" : '').$streamSelectError;
            }
            break;
        }

        foreach ($read as $stream) {
            $chunk = fread($stream, 8192);
            if ($chunk === false || $chunk === '') {
                continue;
            }

            if ($stream === $pipes[1]) {
                $stdout .= $chunk;
                if ($maxBuffer > 0 && strlen($stdout) > $maxBuffer) {
                    $stdout = substr($stdout, -$maxBuffer);
                }
                if ($mirrorOutput) {
                    echo $chunk;
                    fflush(STDOUT);
                }
                continue;
            }

            $stderr .= $chunk;
            if ($maxBuffer > 0 && strlen($stderr) > $maxBuffer) {
                $stderr = substr($stderr, -$maxBuffer);
            }
            if ($mirrorOutput) {
                fwrite(STDERR, $chunk);
                fflush(STDERR);
            }
        }

        if ($timeoutSec > 0 && (microtime(true) - $startedAt) > $timeoutSec) {
            $timedOut = true;
            break;
        }
    }

    return ['stdout' => $stdout, 'stderr' => $stderr, 'timed_out' => $timedOut];
}

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

function pmssCommandTimeoutTerminate($process, int $killAfterSeconds = PMSS_COMMAND_TIMEOUT_KILL_AFTER_DEFAULT): string
{
    if (!is_resource($process) || !function_exists('proc_terminate')) {
        return 'SIGTERM';
    }

    @proc_terminate($process, 15);
    $deadline = microtime(true) + max(0, $killAfterSeconds);
    while (microtime(true) < $deadline) {
        $status = @proc_get_status($process);
        if (!is_array($status) || empty($status['running'])) {
            return 'SIGTERM';
        }
        usleep(100000);
    }

    @proc_terminate($process, 9);
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
    $descriptor = [0 => STDIN, 1 => STDOUT, 2 => STDERR];
    $process = proc_open($bash, $descriptor, $pipes);
    if (!is_resource($process)) {
        usleep(500000);
        $process = proc_open($bash, $descriptor, $pipes);
    }
    if (!is_resource($process)) {
        return ['rc' => 1, 'stdout' => '', 'stderr' => '', 'timed_out' => false, 'launch_failed' => true, 'pipe_failed' => false];
    }

    $startedAt = microtime(true);
    $timedOut = false;
    $lastStatus = null;
    while (true) {
        $status = proc_get_status($process);
        $lastStatus = is_array($status) ? $status : $lastStatus;
        if (!is_array($status) || empty($status['running'])) break;
        if ($timeoutSec > 0 && (microtime(true) - $startedAt) > $timeoutSec) { $timedOut = true; break; }
        usleep(200000);
    }

    $rc = $timedOut
        ? pmssCommandTimeoutClose($process, $timeoutCommand, $timeoutSec, $startedAt)
        : pmssProcessCloseExitCode($process, $lastStatus);
    return ['rc' => $rc, 'stdout' => '', 'stderr' => '', 'timed_out' => $timedOut, 'launch_failed' => false, 'pipe_failed' => false];
}

/**
 * @return array{rc:int,stdout:string,stderr:string,timed_out:bool,launch_failed:bool,pipe_failed:bool}
 */
function pmssCommandPipedCapture(string $bash, string $timeoutCommand, int $timeoutSec, int $maxBuffer = 0, bool $mirrorOutput = false, string $launchError = 'proc_open failed', int $launchRc = 1, bool $retryLaunch = false, string $streamSelectError = 'stream_select failed', ?string $cwd = null, ?array $env = null): array
{
    $pipes = [];
    $process = @proc_open($bash, pmssProcessPipeDescriptorSpec(), $pipes, $cwd, $env);
    if (!is_resource($process) && $retryLaunch) {
        usleep(500000);
        $process = @proc_open($bash, pmssProcessPipeDescriptorSpec(), $pipes, $cwd, $env);
    }
    if (!is_resource($process)) {
        return ['rc' => $launchRc, 'stdout' => '', 'stderr' => $launchError, 'timed_out' => false, 'launch_failed' => true, 'pipe_failed' => false];
    }
    $abortProcess = static function () use (&$process, &$pipes): void {
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
    };

    foreach ([0, 1, 2] as $index) {
        if (!isset($pipes[$index]) || !is_resource($pipes[$index])) {
            $abortProcess();
            return ['rc' => $launchRc, 'stdout' => '', 'stderr' => 'proc_open pipes unavailable', 'timed_out' => false, 'launch_failed' => false, 'pipe_failed' => true];
        }
    }
    fclose($pipes[0]);
    foreach ([1, 2] as $index) {
        if (!@stream_set_blocking($pipes[$index], false)) {
            $abortProcess();
            return ['rc' => $launchRc, 'stdout' => '', 'stderr' => 'proc_open pipes unavailable', 'timed_out' => false, 'launch_failed' => false, 'pipe_failed' => true];
        }
    }

    $startedAt = microtime(true);
    $capture = pmssCommandOutputPipesDrain($pipes, $timeoutSec, $startedAt, $maxBuffer, $mirrorOutput, $streamSelectError);
    foreach ([1, 2] as $index) {
        if (isset($pipes[$index]) && is_resource($pipes[$index])) {
            fclose($pipes[$index]);
        }
    }
    $rc = $capture['timed_out']
        ? pmssCommandTimeoutClose($process, $timeoutCommand, $timeoutSec, $startedAt)
        : pmssProcessCloseExitCode($process);

    return ['rc' => $rc, 'stdout' => $capture['stdout'], 'stderr' => $capture['stderr'], 'timed_out' => $capture['timed_out'], 'launch_failed' => false, 'pipe_failed' => false];
}

/**
 * @return array{rc:int,stdout:string,stderr:string}
 */
function pmssCommandCapture(string $cmd, int $timeoutSec = 0, bool $loginShell = false, string $launchError = 'proc_open failed', int $launchRc = 1): array
{
    $bash = '/bin/bash '.($loginShell ? '-lc ' : '-c ').escapeshellarg($cmd);
    $result = pmssCommandPipedCapture($bash, $cmd, $timeoutSec, 0, false, $launchError, $launchRc);
    return ['rc' => $result['rc'], 'stdout' => $result['stdout'], 'stderr' => $result['stderr']];
}

function pmssCommandIsAptDpkg(string $cmd): bool
{
    return preg_match('/\b(apt-get|apt|dpkg)\b/i', $cmd) === 1;
}

/**
 * Return the environment every apt/dpkg command must inherit.
 *
 * Detached update runs cannot answer debconf, ucf, listchanges, or needrestart
 * prompts. Keep the variables in one place so direct dpkg recovery paths and
 * apt wrappers do not drift apart.
 *
 * @return array<string, string>
 */
function pmssAptDpkgEnvAssignments(array $overrides = []): array
{
    return array_replace([
        'DEBIAN_FRONTEND' => 'noninteractive',
        'APT_LISTCHANGES_FRONTEND' => 'none',
        'UCF_FORCE_CONFDEF' => '1',
        'UCF_FORCE_CONFOLD' => '1',
        'NEEDRESTART_MODE' => 'a',
    ], $overrides);
}

function pmssAptDpkgEnvPrefix(array $overrides = []): string
{
    $parts = [];
    foreach (pmssAptDpkgEnvAssignments($overrides) as $key => $value) {
        $parts[] = $key.'='.$value;
    }

    return implode(' ', $parts);
}

function pmssAptDpkgEnvExportPrefix(?string $pathOverride = null, array $overrides = []): string
{
    $parts = [];
    if ($pathOverride !== null && $pathOverride !== '') {
        $parts[] = 'PATH='.escapeshellarg($pathOverride);
    }
    foreach (pmssAptDpkgEnvAssignments($overrides) as $key => $value) {
        $parts[] = $key.'='.$value;
    }

    return 'export '.implode(' ', $parts).'; ';
}

function pmssCommandTimeoutSeconds(string $cmd): int
{
    $timeoutEnv = getenv('PMSS_COMMAND_TIMEOUT');
    $timeoutSec = ($timeoutEnv !== false && $timeoutEnv !== '' && ctype_digit($timeoutEnv))
        ? (int) $timeoutEnv
        : PMSS_COMMAND_TIMEOUT_DEFAULT;

    return pmssCommandIsAptDpkg($cmd) && $timeoutSec > 0
        ? max($timeoutSec, PMSS_COMMAND_TIMEOUT_APT_DEFAULT)
        : $timeoutSec;
}

function pmssCommandBashInvocation(string $cmd): string
{
    $cmdForShell = $cmd;
    $isAptDpkg = pmssCommandIsAptDpkg($cmd);
    if ($isAptDpkg && preg_match('/[|;]|&&|\|\|/', $cmd) !== 1) {
        $cmdForShell = preg_match('/^(\s*(?:[A-Za-z_][A-Za-z0-9_]*=\S+\s+)+)(.+)$/', $cmd, $match) === 1
            ? rtrim($match[1]).' exec '.ltrim($match[2])
            : 'exec '.$cmd;
    }

    $pathOverride = getenv('PATH');
    $pathShouldApply = $pathOverride !== false && $pathOverride !== '' && preg_match('/(^|\s)PATH=/', $cmdForShell) !== 1;
    if ($isAptDpkg) {
        $cmdForShell = pmssAptDpkgEnvExportPrefix($pathShouldApply ? $pathOverride : null).$cmdForShell;
    } elseif ($pathShouldApply) {
        $cmdForShell = 'PATH='.escapeshellarg($pathOverride).' '.$cmdForShell;
    }

    return '/bin/bash -lc '.escapeshellarg($cmdForShell);
}

/**
 * Emit a non-shell diagnostics snapshot for fork/proc exhaustion scenarios.
 */
function pmssDumpForkDiagnostics(string $context, ?callable $logger = null): void
{
    $now = microtime(true);
    $lastAt = $GLOBALS['PMSS_LAST_FORK_DIAG_AT'] ?? 0.0;
    $lastCtx = $GLOBALS['PMSS_LAST_FORK_DIAG_CONTEXT'] ?? '';
    if ($context === $lastCtx && ($now - (float) $lastAt) < 1.0) {
        return;
    }
    $GLOBALS['PMSS_LAST_FORK_DIAG_AT'] = $now;
    $GLOBALS['PMSS_LAST_FORK_DIAG_CONTEXT'] = $context;

    $log = $logger ?? 'logMessage';
    $prefix = '[FORK] diag: ';
    $pid = function_exists('getmypid') ? getmypid() : null;
    $euid = function_exists('posix_geteuid') ? posix_geteuid() : null;
    $uid = function_exists('posix_getuid') ? posix_getuid() : null;
    $line = $prefix.'context='.trim($context);
    if ($pid !== null) {
        $line .= ' pid='.$pid;
    }
    if ($euid !== null || $uid !== null) {
        $line .= sprintf(' uid=%s euid=%s', $uid !== null ? (string) $uid : 'n/a', $euid !== null ? (string) $euid : 'n/a');
    }
    $log($line);

    $readTrim = static function (string $path, int $maxBytes = 4096): ?string {
        if (!is_readable($path)) {
            return null;
        }
        $data = @file_get_contents($path, false, null, 0, $maxBytes);
        if ($data === false) {
            return null;
        }
        $data = trim((string) $data);
        return $data !== '' ? $data : null;
    };

    $procCount = null;
    $dir = @opendir('/proc');
    if ($dir !== false) {
        $count = 0;
        while (false !== ($entry = readdir($dir))) {
            if ($entry !== '.' && $entry !== '..' && ctype_digit($entry)) {
                $count++;
            }
        }
        closedir($dir);
        $procCount = $count;
    }
    $log($prefix.sprintf(
        'kernel procs=%s pid_max=%s threads_max=%s loadavg=%s',
        $procCount !== null ? (string) $procCount : 'n/a',
        $readTrim('/proc/sys/kernel/pid_max') ?? 'n/a',
        $readTrim('/proc/sys/kernel/threads-max') ?? 'n/a',
        $readTrim('/proc/loadavg') ?? 'n/a'
    ));

    $limitsRaw = $readTrim('/proc/self/limits', 16384);
    if ($limitsRaw !== null) {
        $maxProc = null;
        $maxFiles = null;
        foreach (preg_split('/\r?\n/', $limitsRaw) ?: [] as $limitLine) {
            if ($maxProc === null && strpos($limitLine, 'Max processes') === 0) {
                $maxProc = preg_replace('/\s+/', ' ', trim($limitLine));
            }
            if ($maxFiles === null && strpos($limitLine, 'Max open files') === 0) {
                $maxFiles = preg_replace('/\s+/', ' ', trim($limitLine));
            }
        }
        if ($maxProc !== null || $maxFiles !== null) {
            $log($prefix.'rlimits'
                .($maxProc !== null ? ' | '.$maxProc : '')
                .($maxFiles !== null ? ' | '.$maxFiles : ''));
        }
    }

    $meminfoRaw = $readTrim('/proc/meminfo', 16384);
    if ($meminfoRaw !== null) {
        $wanted = ['MemTotal', 'MemAvailable', 'SwapTotal', 'SwapFree', 'CommitLimit', 'Committed_AS'];
        $vals = [];
        foreach ($wanted as $key) {
            if (preg_match('/^'.$key.':\s+([0-9]+)\s+kB$/m', $meminfoRaw, $match)) {
                $vals[$key] = (int) $match[1];
            }
        }
        if ($vals !== []) {
            $fmtKb = static function (int $kb): string { return sprintf('%0.1fMiB', $kb / 1024.0); };
            $log($prefix.'mem'
                .(isset($vals['MemAvailable']) ? ' avail='.$fmtKb($vals['MemAvailable']) : '')
                .(isset($vals['MemTotal']) ? ' total='.$fmtKb($vals['MemTotal']) : '')
                .(isset($vals['SwapFree']) ? ' swap_free='.$fmtKb($vals['SwapFree']) : '')
                .(isset($vals['SwapTotal']) ? ' swap_total='.$fmtKb($vals['SwapTotal']) : '')
                .(isset($vals['Committed_AS']) ? ' committed='.$fmtKb($vals['Committed_AS']) : '')
                .(isset($vals['CommitLimit']) ? ' commit_limit='.$fmtKb($vals['CommitLimit']) : ''));
        }
    }

    $cgPath = null;
    $cgLines = @file('/proc/self/cgroup', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($cgLines)) {
        foreach ($cgLines as $cgLine) {
            $parts = explode(':', $cgLine, 3);
            if (count($parts) === 3 && $parts[0] === '0') {
                $cgPath = $parts[2];
                break;
            }
        }
        if ($cgPath === null && count($cgLines) > 0) {
            $parts = explode(':', $cgLines[count($cgLines) - 1], 3);
            if (count($parts) === 3) {
                $cgPath = $parts[2];
            }
        }
    }

    if (!is_string($cgPath) || $cgPath === '') {
        return;
    }

    $cgPath = '/'.ltrim($cgPath, '/');
    $ancestors = [];
    $cursor = $cgPath;
    for ($i = 0; $i < 10; $i++) {
        $ancestors[] = $cursor;
        if ($cursor === '/' || $cursor === '') {
            break;
        }
        $parent = dirname($cursor);
        $cursor = $parent === '.' ? '/' : $parent;
    }

    foreach ($ancestors as $path) {
        $pickedDir = null;
        foreach (['/sys/fs/cgroup'.$path, '/sys/fs/cgroup/pids'.$path, '/sys/fs/cgroup/unified'.$path] as $dirPath) {
            if (is_dir($dirPath) && is_readable($dirPath.'/cgroup.procs')) {
                $pickedDir = $dirPath;
                break;
            }
        }
        if ($pickedDir === null) {
            continue;
        }

        $pidsMax = $readTrim($pickedDir.'/pids.max');
        $pidsCur = $readTrim($pickedDir.'/pids.current');
        $pidsEvents = $readTrim($pickedDir.'/pids.events');
        $memMax = $readTrim($pickedDir.'/memory.max');
        $memCur = $readTrim($pickedDir.'/memory.current');
        $memEvents = $readTrim($pickedDir.'/memory.events');
        if ($pidsMax === null && $pidsCur === null && $memMax === null && $memCur === null) {
            continue;
        }

        $procsInCgroup = null;
        $procsRaw = $readTrim($pickedDir.'/cgroup.procs', 262144);
        if ($procsRaw !== null) {
            $trimmed = trim($procsRaw);
            $procsInCgroup = $trimmed === '' ? 0 : (substr_count($trimmed, "\n") + 1);
        }
        $fmtBytes = static function (?string $val): string {
            if ($val === null || $val === 'max' || !ctype_digit($val)) {
                return $val ?? 'n/a';
            }
            return sprintf('%0.1fMiB', ((float) $val) / 1048576.0);
        };

        $pidsEventsMax = ($pidsEvents !== null && preg_match('/^max\s+([0-9]+)$/m', $pidsEvents, $m)) ? $m[1] : null;
        $memEventsOom = ($memEvents !== null && preg_match('/^oom\s+([0-9]+)$/m', $memEvents, $m)) ? $m[1] : null;
        $memEventsOomKill = ($memEvents !== null && preg_match('/^oom_kill\s+([0-9]+)$/m', $memEvents, $m)) ? $m[1] : null;
        $log($prefix.'cgroup'
            .' path='.$path
            .' dir='.$pickedDir
            .($procsInCgroup !== null ? ' procs='.$procsInCgroup : '')
            .(($pidsCur !== null || $pidsMax !== null) ? sprintf(' pids=%s/%s', $pidsCur ?? 'n/a', $pidsMax ?? 'n/a') : '')
            .($pidsEventsMax !== null ? ' pids.events.max='.$pidsEventsMax : '')
            .(($memCur !== null || $memMax !== null) ? sprintf(' mem=%s/%s', $fmtBytes($memCur), $fmtBytes($memMax)) : '')
            .($memEventsOom !== null ? ' mem.events.oom='.$memEventsOom : '')
            .($memEventsOomKill !== null ? ' mem.events.oom_kill='.$memEventsOomKill : ''));
    }
}

function runCommand(string $cmd, bool $verbose = false, ?callable $logger = null, bool $inheritTty = false): int
{
    $log = $logger ?? 'logMessage';
    $failPipeCapture = static function (string $message) use ($log): int {
        $log('[WARN] '.$message);
        fwrite(STDERR, '[PIPE] '.$message.PHP_EOL);
        $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] = ['stdout' => '', 'stderr' => $message];
        return 1;
    };
    $failLaunch = static function () use ($cmd, $log): int {
        $message = '[WARN] Failed to launch command: '.$cmd.'; possible process limit exhaustion (check pids.max / ulimit -u)';
        $log($message);
        $banner = pmssStreamIsTty(STDERR) ? "\033[1;31m[FORK]\033[0m " : '[FORK] ';
        fwrite(STDERR, $banner.$message.PHP_EOL);
        pmssDumpForkDiagnostics('proc_open failed: '.$cmd, $log);
        $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] = ['stdout' => '', 'stderr' => ''];
        return 1;
    };
    $isInteractive = pmssStreamIsTty(STDOUT);
    $timeoutSec = pmssCommandTimeoutSeconds($cmd);
    if ($isInteractive || $verbose) {
        echo ($isInteractive ? "\033[36m[EXEC]\033[0m " : '[CMD] ').$cmd.PHP_EOL;
    }
    $debugRun = getenv('PMSS_RUNCOMMAND_DEBUG');
    $logMemoryUsage = $verbose || ($debugRun !== false && $debugRun !== '');
    $logMemory = static function (string $label) use ($log, $logMemoryUsage): void {
        if ($logMemoryUsage) {
            $log(sprintf('[CMD] memory usage %-6s=%0.2f MiB', $label, memory_get_usage(true) / 1048576));
        }
    };
    $log('[CMD start] '.$cmd);
    $logMemory('before');

    $useInheritedIO = $inheritTty && pmssStandardStreamsAreTty();
    $bash = pmssCommandBashInvocation($cmd);
    $result = $useInheritedIO
        ? pmssCommandInheritedTtyCapture($bash, $cmd, $timeoutSec)
        : pmssCommandPipedCapture($bash, $cmd, $timeoutSec, 1048576, true, '', 1, true);
    if ($result['launch_failed']) {
        return $failLaunch();
    }
    if ($result['pipe_failed']) {
        return $failPipeCapture('proc_open pipes unavailable for command capture: '.$cmd);
    }

    $exitCode = $result['rc'];
    $stdout = $result['stdout'];
    $stderr = $result['stderr'];
    $timedOut = $result['timed_out'];
    $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] = ['stdout' => $stdout, 'stderr' => $stderr];
    if ($exitCode !== 0) {
        $excerpt = trim($stderr);
        $excerpt = $excerpt !== '' ? ' :: '.preg_replace('/\s+/', ' ', substr($excerpt, 0, 300)) : '';
        if ($timedOut) {
            $msg = ($isInteractive ? "\033[1;31m[TIMEOUT]\033[0m " : '[TIMEOUT] ')
                .'Command timed out after '.$timeoutSec.'s: '.$cmd;
            fwrite(STDERR, $msg.PHP_EOL);
            echo $msg.PHP_EOL;
            $log($msg);
        } else {
            $log('[WARN] Command failed (rc='.$exitCode.'): '.$cmd.$excerpt);
        }
    }
    $logMemory('after');
    return $exitCode;
}
