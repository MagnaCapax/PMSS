<?php
/**
 * Step classification helpers for update-step2 failure handling.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

foreach (
    [
        'PMSS_UPDATE_STEP_CLASS_SOFT_FAIL' => 'soft_fail',
        'PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED' => 'must_succeed',
        'PMSS_UPDATE_STEP_CLASS_SKIP_IF_MISSING' => 'skip_if_missing',
    ] as $constant => $value
) {
    if (!defined($constant)) {
        define($constant, $value);
    }
}

if (!function_exists('pmssUpdateStep2HandleClassifiedFailure')) {
    /**
     * Log classified step failures and abort on post-package MUST_SUCCEED steps.
     */
    function pmssUpdateStep2HandleClassifiedFailure(string $description, string $classification, int $rc, string $reason): void
    {
        $severity = ($classification === PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED) ? 'error' : 'warn';
        pmssLogJson([
            'event'          => 'step_failed',
            'severity'       => $severity,
            'classification' => $classification,
            'step'           => $description,
            'rc'             => $rc,
            'reason'         => $reason,
        ]);

        $logLine = sprintf(
            '[%s] Step failed: %s (classification=%s rc=%d reason=%s)',
            strtoupper($severity),
            $description,
            $classification,
            $rc,
            $reason
        );
        $logger = function_exists('logmsg') ? 'logmsg' : 'logMessage';
        $logger($logLine);

        if ($classification === PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED && getenv('PMSS_PACKAGE_PHASE') === 'complete') {
            pmssLogJson([
                'event'  => 'phase',
                'name'   => 'update-step2',
                'status' => 'error',
                'reason' => 'must_succeed_step_failed',
                'step'   => $description,
            ]);
            exit(1);
        }
    }
}
