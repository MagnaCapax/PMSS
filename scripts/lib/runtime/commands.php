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

require_once __DIR__.'/commandPipes.php';
require_once __DIR__.'/commandTimeout.php';
require_once __DIR__.'/commandEnvironment.php';
require_once __DIR__.'/commandDiagnostics.php';
require_once __DIR__.'/commandProcess.php';

/**
 * @return array{rc:int,stdout:string,stderr:string}
 */
function pmssCommandCapture(string $cmd, int $timeoutSec = 0, bool $loginShell = false, string $launchError = 'proc_open failed', int $launchRc = 1): array
{
    // Reject before escapeshellarg() can throw; retain the caller's launch-failure code.
    if (strpos($cmd, "\0") !== false) {
        return ['rc' => $launchRc, 'stdout' => '', 'stderr' => 'unsafe proc_open command'];
    }
    $bash = '/bin/bash '.($loginShell ? '-lc ' : '-c ').escapeshellarg($cmd);
    $result = pmssCommandPipedCapture($bash, $cmd, $timeoutSec, 0, false, $launchError, $launchRc);
    return ['rc' => $result['rc'], 'stdout' => $result['stdout'], 'stderr' => $result['stderr']];
}

function runCommand(string $cmd, bool $verbose = false, ?callable $logger = null, bool $inheritTty = false): int
{
    $log = $logger ?? 'logMessage';
    // Malformed commands must neither launch a truncated prefix nor enter command logs.
    if (strpos($cmd, "\0") !== false) {
        $GLOBALS['PMSS_LAST_COMMAND_OUTPUT'] = ['stdout' => '', 'stderr' => 'unsafe proc_open command'];
        $log('[WARN] unsafe proc_open command');
        return 1;
    }
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
