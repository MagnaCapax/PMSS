<?php
/**
 * Step classification helpers for update-step2 failure handling.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

if (!defined('PMSS_UPDATE_STEP_CLASS_SOFT_FAIL')) {
    define('PMSS_UPDATE_STEP_CLASS_SOFT_FAIL', 'soft_fail');
}
if (!defined('PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED')) {
    define('PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED', 'must_succeed');
}
if (!defined('PMSS_UPDATE_STEP_CLASS_SKIP_IF_MISSING')) {
    define('PMSS_UPDATE_STEP_CLASS_SKIP_IF_MISSING', 'skip_if_missing');
}

if (!function_exists('pmssUpdateStep2HandleClassifiedFailure')) {
    /**
     * Log classified step failures and abort on post-package MUST_SUCCEED steps.
     */
    function pmssUpdateStep2HandleClassifiedFailure(string $description, string $classification, int $rc, string $reason): void
    {
        $isMustSucceed = $classification === PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED;
        $severity = $isMustSucceed ? 'error' : 'warn';
        $jsonEvent = [
            'event'          => 'step_failed',
            'severity'       => $severity,
            'classification' => $classification,
            'step'           => $description,
            'rc'             => $rc,
            'reason'         => $reason,
        ];
        pmssLogJson($jsonEvent);

        $logLine = sprintf(
            '[%s] Step failed: %s (classification=%s rc=%d reason=%s)',
            strtoupper($severity),
            $description,
            $classification,
            $rc,
            $reason
        );
        if (function_exists('logmsg')) {
            logmsg($logLine);
        } else {
            logMessage($logLine);
        }

        if ($isMustSucceed && getenv('PMSS_PACKAGE_PHASE') === 'complete') {
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
