#!/usr/bin/env php
<?php
/**
 * Cron task: system Stats Log.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../lib/systemStats.php';
require_once __DIR__.'/../lib/systemStats/hostPressure.php';

// Periodic stats snapshot for postmortem analysis.
$logDir = '/var/log/pmss';
$logFile = $logDir.'/system-stats.log';
$hostPressureFile = $logDir.'/host-pressure.json';
pmssDirEnsureExists($logDir, 0755);

// Gather metrics from procfs + optional ioping/ps.
$stats = pmssSystemStatsCollect();
$line = pmssSystemStatsLogLine($stats);
// Append as a single parseable line for later analysis.
if (!pmssSystemStatsAppendLogLine($logFile, $line)) {
    fwrite(STDERR, "Warning: failed to append system stats log: {$logFile}\n");
}
// Publish only the current I/O signals needed by unprivileged customer PHP.
if (!pmssSystemStatsHostPressureSnapshotWrite($hostPressureFile, $stats)) {
    fwrite(STDERR, "Warning: failed to write host pressure snapshot: {$hostPressureFile}\n");
}
