<?php
/** Re-enable quota enforcement when quotaFix is cut short after quotaoff. @license GPL-3.0-only */
require_once __DIR__.'/files.php';
require_once __DIR__.'/commands.php';

/**
 * Re-enable quotas after quotaFix was interrupted between quotaoff and its own quotaon.
 *
 * The update step runs quotaFix under a deadline. When that deadline lands inside quotacheck the
 * process is terminated with quota still off, so limits stay unenforced and usage stops moving
 * until the next update. Journaled quota keeps the previous counts consistent, so switching it
 * back on is safe; the unfinished recount is not claimed (the completion stamp is already gone,
 * so the next update recounts, ADR 0140). Only the temporary files an interrupted quotacheck
 * leaves are removed first, with the same guarded helper the normal run uses.
 *
 * @param callable|null $runner      Test seam for pmssCommandCapture().
 * @param callable|null $logger      Test seam for logMessage().
 * @param string        $mountPoint  Mount whose stale quotacheck files are cleaned (default /home).
 * @param int           $pauseMicros Wait for the killed quotacheck to exit before quotaon (tests pass 0).
 *
 * @return array{ok:bool,rc:int,output:string}
 */
function pmssQuotaFixEnforcementRestore(?callable $runner = null, ?callable $logger = null, string $mountPoint = '/home', int $pauseMicros = 500000): array
{
    $log = $logger ?: 'logMessage';
    $log('[quotaFix] WARNING: interrupted after quotaoff; re-enabling quotas (usage was not recounted)');
    if ($pauseMicros > 0) {
        usleep($pauseMicros);
    }
    pmssRemoveStaleQuotaCheckFiles($mountPoint, $log);
    $result = pmssQuotaCommandRun('quotaon -av', $runner);
    $log($result['ok']
        ? '[quotaFix] Quotas re-enabled after interruption'
        : '[quotaFix] WARNING: quotaon failed after interruption (rc='.$result['rc'].'); quota stays off until the next update');

    return $result;
}

/**
 * Arm the restore for a quotaFix run.
 *
 * Signals become a normal exit so shutdown functions run (PHP otherwise dies on SIGTERM without
 * them), and the returned closure disarms the restore once quotaFix has re-enabled quotas itself.
 * Without pcntl only the shutdown path (fatal errors, uncaught exceptions, exit) is covered.
 *
 * @param callable|null $restore Test seam; defaults to pmssQuotaFixEnforcementRestore().
 *
 * @return callable Call it when quotaon has run, so a clean finish does not restore twice.
 */
function pmssQuotaFixRestoreArm(?callable $restore = null): callable
{
    $armed = true;
    $restore = $restore ?: 'pmssQuotaFixEnforcementRestore';
    register_shutdown_function(static function () use (&$armed, $restore): void {
        if ($armed) {
            $armed = false;
            $restore();
        }
    });
    if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
        pcntl_async_signals(true);
        $names = array_filter(['SIGTERM', 'SIGINT', 'SIGHUP'], 'defined');
        foreach ($names as $name) {
            @pcntl_signal(constant($name), static function (int $signal) use ($names): void {
                // A second signal (the deadline watchdog and its backstop both send one) must not cut the restore short.
                foreach ($names as $other) {
                    @pcntl_signal(constant($other), SIG_IGN);
                }
                exit(128 + $signal);
            });
        }
    }

    return static function () use (&$armed): void {
        $armed = false;
    };
}
