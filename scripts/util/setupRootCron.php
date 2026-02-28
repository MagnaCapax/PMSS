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
$systemCrontab = '/etc/crontab';
$cronDropinDir = '/etc/systemd/system/cron.service.d';
$cronDropinFile = $cronDropinDir.'/pmss-restart.conf';

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

// Disable Debian default mdadm monthly check — replaced by PMSS quarterly staggered
// check in root.cron. The default runs all servers on the first Sunday at 00:57,
// causing fleet-wide I/O storms. PMSS version staggers by hostname hash.
$debianMdadmCron = '/etc/cron.d/mdadm';
if (is_file($debianMdadmCron)) {
    $failed = runStep(
        'Removing Debian default mdadm monthly check (replaced by PMSS quarterly staggered)',
        'rm -f '.escapeshellarg($debianMdadmCron)
    ) !== 0 || $failed;
}

// Suppress cron mail globally; PMSS cron jobs already write to explicit logs.
if (is_file($systemCrontab)) {
    $crontabData = @file_get_contents($systemCrontab);
    if (!is_string($crontabData) || $crontabData === '') {
        logmsg('Unable to read system crontab for MAILTO suppression: '.$systemCrontab);
    } else {
        if (preg_match('/^MAILTO=.*$/m', $crontabData) === 1) {
            $updatedCrontab = (string) preg_replace('/^MAILTO=.*$/m', 'MAILTO=""', $crontabData, 1);
        } else {
            $updatedCrontab = 'MAILTO=""'."\n".$crontabData;
        }

        if ($updatedCrontab !== $crontabData) {
            if (@file_put_contents($systemCrontab, $updatedCrontab) === false) {
                logmsg('Failed to write MAILTO suppression to system crontab: '.$systemCrontab);
            } else {
                @chmod($systemCrontab, 0644);
                logmsg('Updated system crontab MAILTO to suppress cron mail');
            }
        }
    }
} else {
    logmsg('System crontab missing; skipping MAILTO suppression');
}

$dropinChanged = false;
if (is_dir('/run/systemd/system')) {
    $dropinContent = "[Service]\nRestart=always\nRestartSec=10\n";
    if (!is_dir($cronDropinDir)) {
        $failed = runStep(
            'Ensuring cron systemd drop-in directory',
            'install -d -m 0755 '.escapeshellarg($cronDropinDir)
        ) !== 0 || $failed;
    }
    $existing = is_file($cronDropinFile) ? file_get_contents($cronDropinFile) : '';
    if ($existing !== $dropinContent) {
        file_put_contents($cronDropinFile, $dropinContent);
        chmod($cronDropinFile, 0644);
        $dropinChanged = true;
    }
}

if ($dropinChanged) {
    $failed = runStep('Reloading systemd unit files (cron restart policy)', 'systemctl daemon-reload || true') !== 0 || $failed;
}

$failed = runStep('Reloading cron daemon', '/etc/init.d/cron force-reload') !== 0 || $failed;
$failed = runStep('Restarting cron daemon', '/etc/init.d/cron restart') !== 0 || $failed;

exit($failed ? 1 : 0);
