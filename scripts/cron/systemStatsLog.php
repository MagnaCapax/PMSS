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
$stats = pmssSystemStatsCollect();
$line = date('Ymd H:i:s');
$fields = [
    'load' => 'load', 'cpu_iowait' => 'cpuIowait', 'mem_total' => 'memTotal', 'mem_free' => 'memFree',
    'mem_cache' => 'memCache', 'mem_buffers' => 'memBuffers', 'swap_total' => 'swapTotal', 'swap_free' => 'swapFree',
    'disk_busy' => 'diskBusy', 'ioping_root' => 'iopingRoot', 'ioping_home' => 'iopingHome', 'top_mem' => 'topMem',
    'psi_io' => 'psiIo', 'psi_mem' => 'psiMem',
];
foreach ($fields as $label => $key) {
    $line .= ' | '.$label.':'.$stats[$key];
}
// Append as a single parseable line for later analysis.
file_put_contents($logFile, $line."\n", FILE_APPEND);
