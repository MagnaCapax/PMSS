#!/usr/bin/env php
<?php
/**
 * Sync the root cron template and restart the daemon using the shared
 * runStep() helper so executions are logged consistently.
 */

require_once __DIR__.'/../lib/logger.php';
require_once __DIR__.'/../lib/update/runtime/commands.php';

requireRoot();

$source = '/etc/seedbox/config/root.cron';
$target = '/etc/cron.d/pmss';

if (!is_readable($source)) {
    logmsg('Root cron template missing; aborting without changes');
    exit(1);
}

$exitCodes = [];
$exitCodes[] = runStep(
    'Deploying root cron template',
    sprintf(
        'install -m 0644 %s %s',
        escapeshellarg($source),
        escapeshellarg($target)
    )
);
$exitCodes[] = runStep('Reloading cron daemon', '/etc/init.d/cron force-reload');
$exitCodes[] = runStep('Restarting cron daemon', '/etc/init.d/cron restart');

$failed = array_filter($exitCodes, static function ($rc) { return $rc !== 0; });
exit($failed ? 1 : 0);
