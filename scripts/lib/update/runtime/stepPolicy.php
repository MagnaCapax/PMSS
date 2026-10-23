<?php
/**
 * Step classification helpers for update-step2 failure handling.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../logging.php';
require_once __DIR__.'/../../runtime/filesystem.php';

defined('PMSS_UPDATE_STEP_CLASS_SOFT_FAIL') || define('PMSS_UPDATE_STEP_CLASS_SOFT_FAIL', 'soft_fail');
defined('PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED') || define('PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED', 'must_succeed');
defined('PMSS_UPDATE_STEP_CLASS_SKIP_IF_MISSING') || define('PMSS_UPDATE_STEP_CLASS_SKIP_IF_MISSING', 'skip_if_missing');

/** Return true only for documented update-step2 failure classes. */
function pmssUpdateStep2ClassificationIsKnown(string $classification): bool
{
    return in_array($classification, [
        PMSS_UPDATE_STEP_CLASS_SOFT_FAIL,
        PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED,
        PMSS_UPDATE_STEP_CLASS_SKIP_IF_MISSING,
    ], true);
}

/**
 * Log classified step failures and abort on post-package MUST_SUCCEED steps.
 */
function pmssUpdateStep2HandleClassifiedFailure(string $description, string $classification, int $rc, string $reason): void
{
    $classificationKnown = pmssUpdateStep2ClassificationIsKnown($classification);
    if (!$classificationKnown) {
        $reason = 'unknown_classification'.($reason !== '' ? ':'.$reason : '');
    }
    $mustSucceed = $classification === PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED || !$classificationKnown;
    $severity = $mustSucceed ? 'error' : 'warn';
    pmssLogJson([
        'event'          => 'step_failed',
        'severity'       => $severity,
        'classification' => $classification,
        'step'           => $description,
        'rc'             => $rc,
        'reason'         => $reason,
    ]);

    logmsg(sprintf(
        '[%s] Step failed: %s (classification=%s rc=%d reason=%s)',
        strtoupper($severity),
        $description,
        $classification,
        $rc,
        $reason
    ));

    if ($mustSucceed && getenv('PMSS_PACKAGE_PHASE') === 'complete') {
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

require_once __DIR__.'/userMaintenanceState.php';
