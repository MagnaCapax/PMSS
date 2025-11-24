<?php
/**
 * Shared runtime helpers for PMSS automation scripts.
 *
 * Provides consistent logging and command execution utilities so that
 * provisioning scripts can emit useful diagnostics without aborting on
 * recoverable errors.
 */

const PMSS_RUNTIME_FALLBACK_LOG = '/var/log/pmss/runtime.log';

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
     */
    function runCommand(string $cmd, bool $verbose = false, ?callable $logger = null): int
    {
        $log = $logger ?? 'logMessage';
        if ($verbose) {
            $log('[CMD] '.$cmd);
        }

        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        // Use a single command string for PHP 7.3 compatibility.
        $bash = '/bin/bash -lc ' . escapeshellarg($cmd);
        $process = proc_open($bash, $descriptor, $pipes);
        if (!is_resource($process)) {
            $log('[WARN] Failed to launch command: '.$cmd);
            $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] = ['stdout' => '', 'stderr' => ''];
            return 1;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $streams = [$pipes[1], $pipes[2]];

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
                    echo $chunk;
                } else {
                    $stderr .= $chunk;
                    fwrite(STDERR, $chunk);
                }
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] = [
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];

        if ($exitCode !== 0) {
            $excerpt = trim($stderr);
            if ($excerpt !== '') {
                $excerpt = ' :: '.preg_replace('/\s+/', ' ', substr($excerpt, 0, 300));
            }
            $log('[WARN] Command failed (rc='.$exitCode.'): '.$cmd.$excerpt);
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
