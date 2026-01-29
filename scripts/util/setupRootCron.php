#!/usr/bin/env php
<?php
/**
 * Sync the root cron template and restart the daemon using the shared
 * runStep() helper so executions are logged consistently.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/logger.php';
require_once __DIR__.'/../lib/update/runtime/commands.php';

requireRoot();

$source = '/etc/seedbox/config/root.cron';
$target = '/etc/cron.d/pmss';
$obsoleteQuotaCron = '/etc/cron.d/updateQuotas';

if (!is_readable($source)) {
    logmsg('Root cron template missing; aborting without changes');
    exit(1);
}

$failed = runStep(
    'Deploying root cron template',
    sprintf(
        'install -m 0644 %s %s',
        escapeshellarg($source),
        escapeshellarg($target)
    )
) !== 0;

// Guardrail: PMSS cron entries must be deployed via /etc/cron.d/pmss (root.cron).
// If a stray /etc/cron.d/updateQuotas entry exists from historical/manual changes,
// remove it so quota refresh runs only under the unified PMSS cron template.
$failed = runStep(
    'Removing obsolete updateQuotas cron.d entry',
    'rm -f '.escapeshellarg($obsoleteQuotaCron)
) !== 0 || $failed;

$failed = runStep('Reloading cron daemon', '/etc/init.d/cron force-reload') !== 0 || $failed;
$failed = runStep('Restarting cron daemon', '/etc/init.d/cron restart') !== 0 || $failed;

exit($failed ? 1 : 0);
