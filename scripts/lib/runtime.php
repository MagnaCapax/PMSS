<?php
/**
 * Shared runtime helpers for PMSS automation scripts.
 *
 * Provides consistent logging and command execution utilities so that
 * provisioning scripts can emit useful diagnostics without aborting on
 * recoverable errors.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

const PMSS_LOG_DIR_DEFAULT = '/var/log/pmss';
const PMSS_RUNTIME_DIR_DEFAULT = '/var/run/pmss';
const PMSS_STATE_DIR_DEFAULT = '/var/lib/pmss';
const PMSS_RUNTIME_FALLBACK_LOG = PMSS_LOG_DIR_DEFAULT.'/runtime.log';
const PMSS_COMMAND_TIMEOUT_DEFAULT = 1200;
const PMSS_COMMAND_TIMEOUT_APT_DEFAULT = 1200;

if (!function_exists('pmssResolvePathFromEnv')) {
    // Resolve a filesystem path from an environment variable with a default.
    function pmssResolvePathFromEnv(string $envKey, string $default): string
    {
        $value = getenv($envKey);
        $value = ($value === false || $value === '') ? $default : $value;
        $value = rtrim($value, '/');
        return $value !== '' ? $value : rtrim($default, '/');
    }
}

if (!function_exists('pmssEnvValueNormalized')) {
    // Normalize environment values so flag parsing stays consistent.
    function pmssEnvValueNormalized($value): string { return strtolower(trim((string) $value)); }
}
if (!function_exists('pmssEnvValueIsFalsey')) {
    // Treat empty and explicit disable values as falsey toggles.
    function pmssEnvValueIsFalsey($value): bool { return in_array(pmssEnvValueNormalized($value), ['', '0', 'false', 'no'], true); }
}

if (!function_exists('pmssLogDir')) {
    // Resolve the PMSS log directory, allowing hermetic test overrides.
    function pmssLogDir(): string
    {
        return pmssResolvePathFromEnv('PMSS_LOG_DIR', PMSS_LOG_DIR_DEFAULT);
    }
}

if (!function_exists('pmssRuntimeDir')) {
    // Resolve the PMSS runtime directory, allowing hermetic test overrides.
    function pmssRuntimeDir(): string
    {
        return pmssResolvePathFromEnv('PMSS_RUNTIME_DIR', PMSS_RUNTIME_DIR_DEFAULT);
    }
}

if (!function_exists('pmssStateDir')) {
    // Resolve the PMSS state directory, allowing hermetic test overrides.
    function pmssStateDir(): string
    {
        return pmssResolvePathFromEnv('PMSS_STATE_DIR', PMSS_STATE_DIR_DEFAULT);
    }
}

if (!function_exists('logMessage')) {
    /**
     * Write a timestamped message to the preferred log file and stdout.
     */
    function logMessage(string $message, ?string $logFile = null): void
    {
        $target = $logFile ?? (defined('PMSS_LOG_FILE') ? PMSS_LOG_FILE : pmssLogDir().'/runtime.log');
        $ts = date('[Y-m-d H:i:s] ');
        @file_put_contents($target, $ts.$message.PHP_EOL, FILE_APPEND | LOCK_EX);
        echo $message.PHP_EOL;
    }
}

if (!function_exists('pmssStreamIsTty')) {
    /** Detect whether a stream resource is attached to a terminal. */
    function pmssStreamIsTty($stream, bool $defaultWhenUnavailable = false): bool
    {
        if (!is_resource($stream)) {
            return $defaultWhenUnavailable;
        }
        if (function_exists('stream_isatty')) {
            return @stream_isatty($stream);
        }
        if (function_exists('posix_isatty')) {
            return @posix_isatty($stream);
        }
        return $defaultWhenUnavailable;
    }
}

if (!function_exists('pmssStandardStreamsAreTty')) {
    /** Detect whether all standard streams are attached to terminals. */
    function pmssStandardStreamsAreTty(): bool
    {
        return pmssStreamIsTty(STDIN) && pmssStreamIsTty(STDOUT) && pmssStreamIsTty(STDERR);
    }
}

if (!function_exists('pmssOutputIndicatesForkFailure')) {
    /**
     * Detect common fork-related failure strings in captured command output.
     *
     * This is intentionally broad and best-effort: it is used only to decide
     * whether to emit additional diagnostics, not to change control flow.
     */
    function pmssOutputIndicatesForkFailure(string $stdout, string $stderr): bool
    {
        $haystack = $stdout."\n".$stderr;
        return preg_match('/\b(Cannot fork|fork failed|Unable to fork|Resource temporarily unavailable)\b/i', $haystack) === 1;
    }
}

if (!function_exists('pmssDumpForkDiagnostics')) {
    /**
     * Emit a best-effort diagnostics snapshot for fork/proc exhaustion scenarios.
     *
     * Must not spawn new processes (do not shell out); keep it safe to call
     * during partial resource exhaustion incidents.
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

        // Kernel/global counters.
        $procCount = null;
        $dir = @opendir('/proc');
        if ($dir !== false) {
            $count = 0;
            while (false !== ($entry = readdir($dir))) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (!ctype_digit($entry)) {
                    continue;
                }
                $count++;
            }
            closedir($dir);
            $procCount = $count;
        }
        $pidMax = $readTrim('/proc/sys/kernel/pid_max');
        $threadsMax = $readTrim('/proc/sys/kernel/threads-max');
        $loadavg = $readTrim('/proc/loadavg');
        $logLine = $prefix.sprintf(
            'kernel procs=%s pid_max=%s threads_max=%s loadavg=%s',
            $procCount !== null ? (string) $procCount : 'n/a',
            $pidMax ?? 'n/a',
            $threadsMax ?? 'n/a',
            $loadavg ?? 'n/a'
        );
        $log($logLine);

        // RLIMITs (prefer /proc/self/limits since it is always available).
        $limitsRaw = $readTrim('/proc/self/limits', 16384);
        if ($limitsRaw !== null) {
            $maxProc = null;
            $maxFiles = null;
            $lines = preg_split('/\r?\n/', $limitsRaw);
            if (is_array($lines)) {
                foreach ($lines as $l) {
                    if ($maxProc === null && strpos($l, 'Max processes') === 0) {
                        $maxProc = preg_replace('/\s+/', ' ', trim($l));
                    }
                    if ($maxFiles === null && strpos($l, 'Max open files') === 0) {
                        $maxFiles = preg_replace('/\s+/', ' ', trim($l));
                    }
                    if ($maxProc !== null && $maxFiles !== null) {
                        break;
                    }
                }
            }
            if ($maxProc !== null || $maxFiles !== null) {
                $limitLine = $prefix.'rlimits'
                    .($maxProc !== null ? ' | '.$maxProc : '')
                    .($maxFiles !== null ? ' | '.$maxFiles : '');
                $log($limitLine);
            }
        }

        // System memory pressure snapshot (to help distinguish EAGAIN vs ENOMEM causes).
        $meminfoRaw = $readTrim('/proc/meminfo', 16384);
        if ($meminfoRaw !== null) {
            $wanted = ['MemTotal', 'MemAvailable', 'SwapTotal', 'SwapFree', 'CommitLimit', 'Committed_AS'];
            $vals = [];
            foreach ($wanted as $k) {
                if (preg_match('/^'.$k.':\s+([0-9]+)\s+kB$/m', $meminfoRaw, $m)) {
                    $vals[$k] = (int) $m[1];
                }
            }
            if (!empty($vals)) {
                $fmtKb = static function (int $kb): string {
                    $mib = $kb / 1024.0;
                    return sprintf('%0.1fMiB', $mib);
                };
                $memLine = $prefix.'mem'
                    .(isset($vals['MemAvailable']) ? ' avail='.$fmtKb($vals['MemAvailable']) : '')
                    .(isset($vals['MemTotal']) ? ' total='.$fmtKb($vals['MemTotal']) : '')
                    .(isset($vals['SwapFree']) ? ' swap_free='.$fmtKb($vals['SwapFree']) : '')
                    .(isset($vals['SwapTotal']) ? ' swap_total='.$fmtKb($vals['SwapTotal']) : '')
                    .(isset($vals['Committed_AS']) ? ' committed='.$fmtKb($vals['Committed_AS']) : '')
                    .(isset($vals['CommitLimit']) ? ' commit_limit='.$fmtKb($vals['CommitLimit']) : '');
                $log($memLine);
            }
        }

        // Cgroup pressure snapshot (pids + memory), walking up the hierarchy to catch parent limits.
        $cgPath = null;
        $cgLines = @file('/proc/self/cgroup', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($cgLines)) {
            foreach ($cgLines as $cgLine) {
                // v2 line format: "0::/user.slice/…/session-XYZ.scope"
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

        if (is_string($cgPath) && $cgPath !== '') {
            $cgPath = '/'.ltrim($cgPath, '/');
            $cgDirsFor = static function (string $path): array {
                $path = '/'.ltrim($path, '/');
                return [
                    // cgroup v2 unified hierarchy
                    '/sys/fs/cgroup'.$path,
                    // cgroup v1 pids controller mount
                    '/sys/fs/cgroup/pids'.$path,
                    // common alternate mount used in some setups
                    '/sys/fs/cgroup/unified'.$path,
                ];
            };

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
                $dirs = $cgDirsFor($path);
                $pickedDir = null;
                foreach ($dirs as $dirPath) {
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

                $procsInCgroup = null;
                $procsRaw = $readTrim($pickedDir.'/cgroup.procs', 262144);
                if ($procsRaw !== null) {
                    $trimmed = trim($procsRaw);
                    $procsInCgroup = $trimmed === '' ? 0 : (substr_count($trimmed, "\n") + 1);
                }

                if ($pidsMax === null && $pidsCur === null && $memMax === null && $memCur === null) {
                    continue;
                }

                $fmtBytes = static function (?string $val): string {
                    if ($val === null) {
                        return 'n/a';
                    }
                    if ($val === 'max') {
                        return 'max';
                    }
                    if (!ctype_digit($val)) {
                        return $val;
                    }
                    $bytes = (float) $val;
                    $mib = $bytes / 1048576.0;
                    return sprintf('%0.1fMiB', $mib);
                };

                $pidsEventsMax = null;
                if ($pidsEvents !== null && preg_match('/^max\\s+([0-9]+)$/m', $pidsEvents, $m)) {
                    $pidsEventsMax = $m[1];
                }
                $memEventsOom = null;
                if ($memEvents !== null && preg_match('/^oom\\s+([0-9]+)$/m', $memEvents, $m)) {
                    $memEventsOom = $m[1];
                }
                $memEventsOomKill = null;
                if ($memEvents !== null && preg_match('/^oom_kill\\s+([0-9]+)$/m', $memEvents, $m)) {
                    $memEventsOomKill = $m[1];
                }

                $cgLineOut = $prefix.'cgroup'
                    .' path='.$path
                    .' dir='.$pickedDir
                    .($procsInCgroup !== null ? ' procs='.$procsInCgroup : '')
                    .(($pidsCur !== null || $pidsMax !== null) ? sprintf(' pids=%s/%s', $pidsCur ?? 'n/a', $pidsMax ?? 'n/a') : '')
                    .($pidsEventsMax !== null ? ' pids.events.max='.$pidsEventsMax : '')
                    .(($memCur !== null || $memMax !== null) ? sprintf(' mem=%s/%s', $fmtBytes($memCur), $fmtBytes($memMax)) : '')
                    .($memEventsOom !== null ? ' mem.events.oom='.$memEventsOom : '')
                    .($memEventsOomKill !== null ? ' mem.events.oom_kill='.$memEventsOomKill : '');
                $log($cgLineOut);
            }
        }
    }
}

if (!function_exists('runCommand')) {
    /**
     * Execute a shell command while keeping failures non-fatal.
     *
     * Streams stdout/stderr live, keeps only a tail of output in memory for
     * runStep() excerpts, and emits an interactive banner so operators can see
     * which command is in-flight when running manually.
     */
    function runCommand(string $cmd, bool $verbose = false, ?callable $logger = null, bool $inheritTty = false): int
    {
        $log = $logger ?? 'logMessage';
        $isInteractive = pmssStreamIsTty(STDOUT);
        $timeoutEnv = getenv('PMSS_COMMAND_TIMEOUT');
        $timeoutSec = PMSS_COMMAND_TIMEOUT_DEFAULT;
        if ($timeoutEnv !== false && $timeoutEnv !== '' && ctype_digit($timeoutEnv)) {
            // 0 = no timeout (infinite); positive = timeout in seconds.
            $timeoutSec = (int) $timeoutEnv;
        }
        // APT/dpkg operations legitimately take a long time (especially dist-upgrades).
        // Always apply a sane floor even when PMSS_COMMAND_TIMEOUT is set lower.
        $isAptDpkgCommand = preg_match('/\b(apt-get|apt|dpkg)\b/i', $cmd) === 1;
        if ($isAptDpkgCommand && $timeoutSec > 0) {
            $timeoutSec = max($timeoutSec, PMSS_COMMAND_TIMEOUT_APT_DEFAULT);
        }
        $announceStart = $isInteractive || $verbose;
        if ($announceStart) {
            $prefix = $isInteractive ? "\033[36m[EXEC]\033[0m " : '[CMD] ';
            echo $prefix.$cmd.PHP_EOL;
        }
        $debugRun = getenv('PMSS_RUNCOMMAND_DEBUG');
        $logMemoryUsage = $verbose || ($debugRun !== false && $debugRun !== '');
        $log('[CMD start] '.$cmd);
        if ($logMemoryUsage) {
            $log(sprintf('[CMD] memory usage before=%0.2f MiB', memory_get_usage(true) / 1048576));
        }

        $useInheritedIO = false;
        if ($inheritTty) {
            $useInheritedIO = pmssStandardStreamsAreTty();
        }

        if ($useInheritedIO) {
            $descriptor = [
                0 => STDIN,
                1 => STDOUT,
                2 => STDERR,
            ];
        } else {
            $descriptor = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
        }
        // Use a single command string for PHP 7.3 compatibility.
        // For apt/dpkg, prefer exec when safe so timeouts terminate the real child process.
        // Context: output capture existed at least by 8e3bd4b (pre-Dec) with unbounded buffers.
        // a2ca2bbf6bf added timeouts + tail-capped buffers for hung/noisy commands, and
        // cc05325aaf1 added exec for apt/dpkg; exec+env broke DEBIAN_FRONTEND-prefixed
        // commands (rc=127), so we insert exec after env assignments here.
        // If regressions continue (e.g., interactive dist-upgrade), re-evaluate rolling back
        // these runtime changes instead of piling on more workarounds.
        // References: docs/adr/0006-update-step2-user-loop-and-observability.md,
        // docs/contracts.md (runCommand contract/timeout/buffering notes).
        $cmdForShell = $cmd;
        if ($isAptDpkgCommand
            && strpos($cmd, ';') === false
            && strpos($cmd, '&&') === false
            && strpos($cmd, '||') === false
            && strpos($cmd, '|') === false) {
            if (preg_match('/^(\\s*(?:[A-Za-z_][A-Za-z0-9_]*=\\S+\\s+)+)(.+)$/', $cmd, $match)) {
                $cmdForShell = rtrim($match[1]).' exec '.ltrim($match[2]);
            } else {
                $cmdForShell = 'exec '.$cmd;
            }
        }
        $pathOverride = getenv('PATH');
        if ($pathOverride !== false && $pathOverride !== '' && preg_match('/(^|\s)PATH=/', $cmdForShell) !== 1) {
            $cmdForShell = 'PATH='.escapeshellarg($pathOverride).' '.$cmdForShell;
        }
        $bash = '/bin/bash -lc '.escapeshellarg($cmdForShell);
        $process = proc_open($bash, $descriptor, $pipes);
        if (!is_resource($process)) {
            // Simple one-shot retry for transient fork failures under load.
            usleep(500000);
            $process = proc_open($bash, $descriptor, $pipes);
        }
        if (!is_resource($process)) {
            $hint = '';
            $pidsMaxPaths = [
                '/sys/fs/cgroup/pids.max',      // unified cgroup v2 (root)
                '/sys/fs/cgroup/pids/pids.max', // cgroup v1 (root)
            ];
            foreach ($pidsMaxPaths as $p) {
                if (is_readable($p)) {
                    $val = trim((string) @file_get_contents($p));
                    if ($val !== '') {
                        $hint = ' (pids.max='.$val.')';
                        break;
                    }
                }
            }

            // Best-effort: capture the current cgroup pids controller state so
            // operators can see whether a session/user slice is exhausting its
            // pid quota when forks fail under load.
            $cgInfo = '';
            $cgPath = '';
            $cgroupFile = '/proc/self/cgroup';
            if (is_readable($cgroupFile)) {
                $lines = @file($cgroupFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (is_array($lines)) {
                    foreach ($lines as $line) {
                        // cgroup v2 line format: "0::/user.slice/…/session-XYZ.scope"
                        $parts = explode(':', $line, 3);
                        if (count($parts) === 3 && $parts[0] === '0') {
                            $cgPath = $parts[2];
                            break;
                        }
                    }
                    // Fallback for cgroup v1-style lines if v2 format was not found.
                    if ($cgPath === '' && count($lines) > 0) {
                        $parts = explode(':', $lines[count($lines) - 1], 3);
                        if (count($parts) === 3) {
                            $cgPath = $parts[2];
                        }
                    }
                }
            }
            if ($cgPath !== '') {
                $cgRoot       = '/sys/fs/cgroup';
                $cgPidsMax = $cgRoot.$cgPath.'/pids.max';
                $cgPidsCurrent = $cgRoot.$cgPath.'/pids.current';
                $maxVal = is_readable($cgPidsMax) ? trim((string) @file_get_contents($cgPidsMax)) : '';
                $curVal = is_readable($cgPidsCurrent) ? trim((string) @file_get_contents($cgPidsCurrent)) : '';
                if ($maxVal !== '' || $curVal !== '') {
                    $cgInfo = sprintf(
                        ' [cgroup=%s pids.max=%s current=%s]',
                        $cgPath,
                        $maxVal !== '' ? $maxVal : 'n/a',
                        $curVal !== '' ? $curVal : 'n/a'
                    );
                }
            }

            // Capture a rough process count and kernel pid_max so fork failures
            // can be correlated with global limits without spawning helpers.
            $procInfo = '';
            $procCount = null;
            $threadsMax = null;
            $pidMaxFile = '/proc/sys/kernel/pid_max';
            if (is_readable($pidMaxFile)) {
                $threadsMax = trim((string) @file_get_contents($pidMaxFile));
            }
            $dir = @opendir('/proc');
            if ($dir !== false) {
                $count = 0;
                while (false !== ($entry = readdir($dir))) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    if (!ctype_digit($entry)) {
                        continue;
                    }
                    $count++;
                }
                closedir($dir);
                $procCount = $count;
            }
            if ($procCount !== null || $threadsMax !== null) {
                $procInfo = sprintf(
                    ' [procs=%s pid_max=%s]',
                    $procCount !== null ? (string) $procCount : 'n/a',
                    $threadsMax !== null && $threadsMax !== '' ? $threadsMax : 'n/a'
                );
            }

            $message = '[WARN] Failed to launch command: '.$cmd.$hint.$cgInfo.$procInfo.'; possible process limit exhaustion (check pids.max / ulimit -u)';
            $log($message);
            $isTty = pmssStreamIsTty(STDERR);
            $banner = $isTty ? "\033[1;31m[FORK]\033[0m " : '[FORK] ';
            fwrite(STDERR, $banner.$message.PHP_EOL);
            pmssDumpForkDiagnostics('proc_open failed: '.$cmd, $log);
            $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] = ['stdout' => '', 'stderr' => ''];
            return 1;
        }

        if ($useInheritedIO) {
            $startedAt = microtime(true);
            $timedOut = false;

            while (true) {
                $status = proc_get_status($process);
                if (!is_array($status) || empty($status['running'])) {
                    break;
                }
                if ($timeoutSec > 0 && (microtime(true) - $startedAt) > $timeoutSec) {
                    $timedOut = true;
                    break;
                }
                usleep(200000);
            }

            if ($timedOut) {
                if (function_exists('proc_terminate')) {
                    @proc_terminate($process);
                }
                $exitCode = 124;
            } else {
                $exitCode = proc_close($process);
            }

            $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] = ['stdout' => '', 'stderr' => ''];

            if ($exitCode !== 0) {
                if ($timedOut) {
                    $banner = $isInteractive ? "\033[1;31m[TIMEOUT]\033[0m " : '[TIMEOUT] ';
                    $msg = $banner.'Command timed out after '.$timeoutSec.'s: '.$cmd;
                    fwrite(STDERR, $msg.PHP_EOL);
                    echo $msg.PHP_EOL;
                    $log($msg);
                } else {
                    $log('[WARN] Command failed (rc='.$exitCode.'): '.$cmd);
                }
            }
            if ($logMemoryUsage) {
                $log(sprintf('[CMD] memory usage after =%0.2f MiB', memory_get_usage(true) / 1048576));
            }
            return $exitCode;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $maxBuffer = 1048576; // keep ~1MiB tail per stream to avoid RSS explosion
        $startedAt = microtime(true);
        $timedOut = false;

        while (!feof($pipes[1]) || !feof($pipes[2])) {
            $read = [];
            if (!feof($pipes[1])) $read[] = $pipes[1];
            if (!feof($pipes[2])) $read[] = $pipes[2];
            if (empty($read)) {
                break;
            }
            $write = $except = [];
            $ready = stream_select($read, $write, $except, 0, 200000);
            if ($ready === false) {
                break;
            }
            foreach ($read as $stream) {
                $chunk = fread($stream, 8192);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($stream === $pipes[1]) {
                    $stdout .= $chunk;
                    if (strlen($stdout) > $maxBuffer) {
                        $stdout = substr($stdout, -$maxBuffer);
                    }
                    echo $chunk;
                    fflush(STDOUT);
                } else {
                    $stderr .= $chunk;
                    if (strlen($stderr) > $maxBuffer) {
                        $stderr = substr($stderr, -$maxBuffer);
                    }
                    fwrite(STDERR, $chunk);
                    fflush(STDERR);
                }
            }
            if ($timeoutSec > 0 && (microtime(true) - $startedAt) > $timeoutSec) {
                $timedOut = true;
                break;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        if ($timedOut) {
            // Best-effort termination; do not abort the whole script.
            if (function_exists('proc_terminate')) {
                @proc_terminate($process);
            }
            $exitCode = 124;
        } else {
            $exitCode = proc_close($process);
        }

        $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] = [
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];

        if ($exitCode !== 0) {
            $excerpt = trim($stderr);
            if ($excerpt !== '') {
                $excerpt = ' :: '.preg_replace('/\s+/', ' ', substr($excerpt, 0, 300));
            }
            if ($timedOut) {
                $banner = $isInteractive ? "\033[1;31m[TIMEOUT]\033[0m " : '[TIMEOUT] ';
                $msg = $banner.'Command timed out after '.$timeoutSec.'s: '.$cmd;
                fwrite(STDERR, $msg.PHP_EOL);
                echo $msg.PHP_EOL;
                $log($msg);
            } else {
                $log('[WARN] Command failed (rc='.$exitCode.'): '.$cmd.$excerpt);
            }
        }
        if ($logMemoryUsage) {
            $log(sprintf('[CMD] memory usage after =%0.2f MiB', memory_get_usage(true) / 1048576));
        }
        return $exitCode;
    }
}

if (!function_exists('requireRoot')) {
    /**
     * Abort with a clear error when the current user is not root.
     */
    function requireRoot(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            pmssError("This script must be run as root.");
            exit(1);
        }
    }
}

if (!function_exists('pmssRequireCli')) {
    /**
     * Enforce CLI execution for script entrypoints and reusable CLI flows.
     */
    function pmssRequireCli(string $message = 'This script must be run from the command line.', ?int $failureCode = 1): bool
    {
        if (PHP_SAPI === 'cli') {
            return true;
        }

        fwrite(STDERR, rtrim($message, "\r\n").PHP_EOL);
        if ($failureCode !== null) {
            exit($failureCode);
        }

        return false;
    }
}

if (!function_exists('pmssPrepareCliEntrypoint')) {
    /**
     * Apply the standard CLI/bootstrap guard used by thin script wrappers.
     */
    function pmssPrepareCliEntrypoint(bool $rootRequired = false, array $argvAppend = []): void
    {
        pmssRequireCli();
        if ($rootRequired) {
            requireRoot();
        }

        if (empty($argvAppend)) {
            return;
        }

        if (!isset($GLOBALS['argv']) || !is_array($GLOBALS['argv'])) {
            $GLOBALS['argv'] = $_SERVER['argv'] ?? [];
        }
        if (!isset($_SERVER['argv']) || !is_array($_SERVER['argv'])) {
            $_SERVER['argv'] = $GLOBALS['argv'];
        }

        foreach ($argvAppend as $arg) {
            $arg = (string) $arg;
            $GLOBALS['argv'][] = $arg;
            $_SERVER['argv'][] = $arg;
        }
    }
}

if (!function_exists('pmssRequireCliEntrypointScript')) {
    function pmssRequireCliEntrypointScript(string $baseDir, string $relativePath, bool $rootRequired = false, array $argvAppend = []): void
    {
        pmssPrepareCliEntrypoint($rootRequired, $argvAppend);
        require_once rtrim($baseDir, '/').'/'.ltrim($relativePath, '/');
    }
}

if (!function_exists('pmssSnapshotLogOpen')) {
    /**
     * Open a root-only append log for snapshot-style cron jobs.
     *
     * @return resource|false
     */
    function pmssSnapshotLogOpen(string $scriptName, string $logPath, ?int &$oldUmask)
    {
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            fwrite(STDERR, basename($scriptName)." must be run as root.\n");
            return false;
        }
        $oldUmask = umask(0077);
        $logDir = dirname($logPath);
        if (!is_dir($logDir) && !@mkdir($logDir, 0755, true) && !is_dir($logDir)) {
            return false;
        }
        $handle = @fopen($logPath, 'ab');
        if ($handle === false) {
            return false;
        }
        @chmod($logPath, 0600);
        if (function_exists('flock')) {
            @flock($handle, LOCK_EX);
        }
        return $handle;
    }
}

if (!function_exists('pmssWithSnapshotLog')) {
    /**
     * Run a snapshot job with the shared root-only log lifecycle.
     *
     * The callback receives the opened log handle and must return an integer
     * exit status so cron entrypoints can keep their existing semantics.
     */
    function pmssWithSnapshotLog(string $scriptName, string $logPath, callable $callback): int
    {
        $oldUmask = null;
        $handle = false;

        try {
            $handle = pmssSnapshotLogOpen($scriptName, $logPath, $oldUmask);
            if ($handle === false) {
                return 1;
            }

            return (int) $callback($handle);
        } finally {
            if ($handle !== false) {
                @fclose($handle);
            }

            if ($oldUmask !== null) {
                umask($oldUmask);
            }
        }
    }
}

if (!function_exists('pmssError')) {
    /**
     * Write an error message to STDERR and the log.
     */
    function pmssError(string $message): void
    {
        // Use ANSI red for visibility if interactive, otherwise plain text
        $isTty = pmssStreamIsTty(STDERR);
        $prefix = $isTty ? "\033[31m[ERROR]\033[0m " : "[ERROR] ";
        
        fwrite(STDERR, $prefix . $message . PHP_EOL);
        logMessage('[ERROR] ' . $message); // Persist to logfile
    }
}
