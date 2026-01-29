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
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

// Gather metrics from procfs + optional ioping/ps.
$stats = pmssSystemStatsCollect();
$line = sprintf(
    '%s | load:%s | cpu_iowait:%s | mem_total:%s | mem_free:%s | mem_cache:%s | mem_buffers:%s | swap_total:%s | swap_free:%s | disk_busy:%s | ioping_root:%s | ioping_home:%s | top_mem:%s | psi_io:%s | psi_mem:%s',
    date('Ymd H:i:s'),
    $stats['load'],
    $stats['cpuIowait'],
    $stats['memTotal'],
    $stats['memFree'],
    $stats['memCache'],
    $stats['memBuffers'],
    $stats['swapTotal'],
    $stats['swapFree'],
    $stats['diskBusy'],
    $stats['iopingRoot'],
    $stats['iopingHome'],
    $stats['topMem'],
    $stats['psiIo'],
    $stats['psiMem']
);
// Append as a single parseable line for later analysis.
file_put_contents($logFile, $line."\n", FILE_APPEND);
