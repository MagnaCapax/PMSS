<?php
/**
 * System stats snapshot helpers for cron logging.
 *
 * @license GPL-3.0-only
 * @author  PMSS Team
 */

require_once __DIR__.'/systemStats/parse.php';
pmssRequireRelativeFiles(__DIR__.'/systemStats', ['observe.php', 'log.php']);

/**
 * Collect a single snapshot of system metrics for logging.
 *
 * @return array<string, string> Metric values formatted for log output.
 */
function pmssSystemStatsCollect(): array
{
    // Keep this low in test mode so hermetic tests don't waste time sleeping.
    $sampleUsec = pmssTestModeEnabled() ? 50000 : 1000000;

    $cpu1 = pmssSystemStatsCpuCountersFromRaw(pmssReadRegularFileContents('/proc/stat'));
    $disk1 = pmssSystemStatsDiskIoTimeFromRaw(pmssReadRegularFileContents('/proc/diskstats'));
    usleep($sampleUsec);
    $cpu2 = pmssSystemStatsCpuCountersFromRaw(pmssReadRegularFileContents('/proc/stat'));
    $disk2 = pmssSystemStatsDiskIoTimeFromRaw(pmssReadRegularFileContents('/proc/diskstats'));

    $meminfo = pmssProcMeminfoFieldsRead();

    $hasIoping = pmssCommandPath('ioping') !== '';

    return [
        'load'       => pmssSystemStatsLoadAverageFromRaw(pmssReadRegularFileContents('/proc/loadavg')),
        'cpuIowait'   => pmssSystemStatsCpuIowaitPercent($cpu1, $cpu2),
    ] + array_map(static function (string $field) use ($meminfo): string { return pmssSystemStatsKbToHuman($meminfo[$field] ?? 0); }, [
        'memTotal' => 'MemTotal', 'memFree' => 'MemFree', 'memCache' => 'Cached',
        'memBuffers' => 'Buffers', 'swapTotal' => 'SwapTotal', 'swapFree' => 'SwapFree',
    ]) + [
        'diskBusy'    => pmssSystemStatsDiskBusyPercent($disk1, $disk2, $sampleUsec / 1000000),
        'iopingRoot'  => $hasIoping ? pmssSystemStatsIopingMs('/') : 'na',
        'iopingHome'  => $hasIoping ? pmssSystemStatsIopingMs('/home') : 'na',
        'topMem'      => pmssSystemStatsTopMemoryProcesses(),
        'psiIo'       => pmssSystemStatsPsiRead('/proc/pressure/io'),
        'psiMem'      => pmssSystemStatsPsiRead('/proc/pressure/memory'),
        'jbd2Home'    => pmssSystemStatsJbd2HomeRead(),
    ];
}
