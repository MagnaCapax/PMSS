#!/usr/bin/env php
<?php
/**
 * Cron task: system Stats Log.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../lib/systemStats.php';

// Periodic stats snapshot for postmortem analysis.
$logDir = '/var/log/pmss';
$logFile = $logDir.'/system-stats.log';
pmssDirEnsureExists($logDir, 0755);

// Gather metrics from procfs + optional ioping/ps.
$line = pmssSystemStatsLogLine(pmssSystemStatsCollect());
// Append as a single parseable line for later analysis.
file_put_contents($logFile, $line."\n", FILE_APPEND);
