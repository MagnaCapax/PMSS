<?php
/**
 * Shared runtime helpers for PMSS automation scripts.
 *
 * Provides consistent logging and command execution utilities so that
 * provisioning scripts can emit useful diagnostics without aborting on
 * recoverable errors.
 */

const PMSS_RUNTIME_FALLBACK_LOG = '/var/log/pmss/runtime.log';
const PMSS_COMMAND_TIMEOUT_DEFAULT = 300;

if (!function_exists('logMessage')) {
    /**
     * Write a timestamped message to the preferred log file and stdout.
     */
    function logMessage(string $message, ?string $logFile = null): void
    {
        $target = $logFile ?? (defined('PMSS_LOG_FILE') ? PMSS_LOG_FILE : PMSS_RUNTIME_FALLBACK_LOG);
        $ts = date('[Y-m-d H:i:s] ');
        @file_put_contents($target, $ts.$message.PHP_EOL, FILE_APPEND | LOCK_EX);
        echo $message.PHP_EOL;
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
    function runCommand(string $cmd, bool $verbose = false, ?callable $logger = null): int
    {
        $log = $logger ?? 'logMessage';
        $isInteractive = function_exists('posix_isatty') && posix_isatty(STDOUT);
        $timeoutEnv = getenv('PMSS_COMMAND_TIMEOUT');
        $timeoutSec = PMSS_COMMAND_TIMEOUT_DEFAULT;
        if ($timeoutEnv !== false && $timeoutEnv !== '' && ctype_digit($timeoutEnv)) {
            $val = (int) $timeoutEnv;
            if ($val > 0) {
                $timeoutSec = $val;
            }
        }
        $announceStart = $isInteractive || $verbose;
        if ($announceStart) {
            $prefix = $isInteractive ? "\033[36m[EXEC]\033[0m " : '[CMD] ';
            echo $prefix.$cmd.PHP_EOL;
        }
        $debugRun = getenv('PMSS_RUNCOMMAND_DEBUG');
        $log('[CMD start] '.$cmd);
        if ($verbose || ($debugRun !== false && $debugRun !== '')) {
            $log(sprintf('[CMD] memory usage before=%0.2f MiB', memory_get_usage(true) / 1048576));
        }

        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        // Use a single command string for PHP 7.3 compatibility.
        $bash = '/bin/bash -lc '.escapeshellarg($cmd);
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
            $isTty = function_exists('posix_isatty') && posix_isatty(STDERR);
            $banner = $isTty ? "\033[1;31m[FORK]\033[0m " : '[FORK] ';
            fwrite(STDERR, $banner.$message.PHP_EOL);
            $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] = ['stdout' => '', 'stderr' => ''];
            return 1;
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
            if ((microtime(true) - $startedAt) > $timeoutSec) {
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
        if ($verbose || ($debugRun !== false && $debugRun !== '')) {
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
            pmssFatal("This script must be run as root.");
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
        $isTty = function_exists('posix_isatty') && posix_isatty(STDERR);
        $prefix = $isTty ? "\033[31m[ERROR]\033[0m " : "[ERROR] ";
        
        fwrite(STDERR, $prefix . $message . PHP_EOL);
        logMessage('[ERROR] ' . $message); // Persist to logfile
    }
}

if (!function_exists('pmssFatal')) {
    /**
     * Report an error and exit with a non-zero status code.
     */
    function pmssFatal(string $message, int $code = 1): void
    {
        pmssError($message);
        exit($code);
    }
}
