<?php
/**
 * Step classification helpers for update-step2 failure handling.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../logging.php';

defined('PMSS_UPDATE_STEP_CLASS_SOFT_FAIL') || define('PMSS_UPDATE_STEP_CLASS_SOFT_FAIL', 'soft_fail');
defined('PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED') || define('PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED', 'must_succeed');
defined('PMSS_UPDATE_STEP_CLASS_SKIP_IF_MISSING') || define('PMSS_UPDATE_STEP_CLASS_SKIP_IF_MISSING', 'skip_if_missing');

/**
 * Log classified step failures and abort on post-package MUST_SUCCEED steps.
 */
function pmssUpdateStep2HandleClassifiedFailure(string $description, string $classification, int $rc, string $reason): void
{
    $mustSucceed = $classification === PMSS_UPDATE_STEP_CLASS_MUST_SUCCEED;
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

/**
 * Durable marker path for an incomplete per-user-maintenance tail (GH#592).
 *
 * Overridable via PMSS_INCOMPLETE_USER_MAINTENANCE_PATH for tests.
 */
function pmssUpdateIncompleteUserMaintenancePath(): string
{
    $override = (string) getenv('PMSS_INCOMPLETE_USER_MAINTENANCE_PATH');
    return $override !== '' ? $override : '/var/lib/pmss/update-incomplete-users.json';
}

/**
 * Record that per-user maintenance finished with a skipped tail (GH#592).
 *
 * Replaces the only real benefit the former MUST_SUCCEED hard-fail provided —
 * surfacing the incomplete tail — without blocking the whole system update.
 * The marker is durable (survives reboot) so operators / agentDiagnostics /
 * MOTD can see that some users still need a permission+skel refresh.
 *
 * @param array<int, string> $skipReasons
 */
function pmssUpdateRecordIncompleteUserMaintenance(int $processed, int $total, array $skipReasons): void
{
    $path = pmssUpdateIncompleteUserMaintenancePath();
    @mkdir(dirname($path), 0755, true);
    $payload = [
        'ts'        => gmdate('c'),
        'processed' => $processed,
        'total'     => $total,
        'skipped'   => array_values($skipReasons),
    ];
    @file_put_contents($path, (pmssJsonEncodePretty($payload) ?? '')."\n");
}

/**
 * Clear the incomplete-tail marker once a run processes all users (GH#592).
 */
function pmssUpdateClearIncompleteUserMaintenance(): void
{
    $path = pmssUpdateIncompleteUserMaintenancePath();
    if (is_file($path)) {
        @unlink($path);
    }
}
