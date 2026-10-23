<?php
/**
 * System stats snapshot helpers for cron logging.
 *
 * @license GPL-3.0-only
 * @author  PMSS Team
 */

require_once __DIR__.'/systemStats/parse.php';

/**
 * Collect the bounded top-memory process summary while checking command rc.
 */
function pmssSystemStatsTopMemoryProcesses(?callable $runner = null): string
{
    $runner = $runner ?? static function (array &$output, int &$rc): void {
        exec('/bin/bash -o pipefail -c '.escapeshellarg('ps -eo comm=,rss= --sort=-rss | head -n 3'), $output, $rc);
    };

    $output = []; $rc = 1;
    try {
        $runner($output, $rc);
    } catch (Throwable $exception) {
        return 'na';
    }

    return $rc === 0 ? pmssSystemStatsTopMemoryFromPsRows($output) : 'na';
}

/** Read one PSI file and degrade missing/unreadable hosts to `na`. */
function pmssSystemStatsPsiRead(string $path): string { return is_readable($path) ? pmssSystemStatsPsiFromRaw(pmssReadRegularFileContents($path)) : 'na'; }

/** Format optional representative ioping latency for the stats log. */
function pmssSystemStatsIopingMs(string $path): string { $value = is_dir($path) ? pmssIopingMedianMs($path) : null; return $value === null ? 'na' : number_format($value, 1, '.', '').'ms'; }

/** Render one parseable cron log line from collected stats. */
function pmssSystemStatsLogLine(array $stats, ?string $timestamp = null): string
{
    $line = $timestamp ?? date('Ymd H:i:s');
    foreach ([
        'load' => 'load', 'cpu_iowait' => 'cpuIowait', 'mem_total' => 'memTotal', 'mem_free' => 'memFree',
        'mem_cache' => 'memCache', 'mem_buffers' => 'memBuffers', 'swap_total' => 'swapTotal', 'swap_free' => 'swapFree', 'disk_busy' => 'diskBusy', 'ioping_root' => 'iopingRoot', 'ioping_home' => 'iopingHome', 'top_mem' => 'topMem', 'psi_io' => 'psiIo', 'psi_mem' => 'psiMem',
    ] as $label => $key) {
        $line .= ' | '.$label.':'.(string) ($stats[$key] ?? 'na');
    }
    return $line;
}

/** Append one system-stats log line after checking the target path shape. */
function pmssSystemStatsAppendLogLine(string $path, string $line): bool
{
    if ($path === '' || pmssFilesystemPathHasNulByte($path) || $path[0] !== '/') return false;
    if (!pmssDirEnsureExists(dirname($path), 0755)) return false;
    if (!pmssLockFilePathIsSafe($path)) return false;

    $record = rtrim($line, "\r\n")."\n";
    // A partial record must reach the cron caller's existing failure warning.
    return pmssFileWriteComplete($path, $record, FILE_APPEND | LOCK_EX);
}

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
        'memTotal'    => pmssSystemStatsKbToHuman($meminfo['MemTotal'] ?? 0),
        'memFree'     => pmssSystemStatsKbToHuman($meminfo['MemFree'] ?? 0),
        'memCache'    => pmssSystemStatsKbToHuman($meminfo['Cached'] ?? 0),
        'memBuffers'  => pmssSystemStatsKbToHuman($meminfo['Buffers'] ?? 0),
        'swapTotal'   => pmssSystemStatsKbToHuman($meminfo['SwapTotal'] ?? 0),
        'swapFree'    => pmssSystemStatsKbToHuman($meminfo['SwapFree'] ?? 0),
        'diskBusy'    => pmssSystemStatsDiskBusyPercent($disk1, $disk2, $sampleUsec / 1000000),
        'iopingRoot'  => $hasIoping ? pmssSystemStatsIopingMs('/') : 'na',
        'iopingHome'  => $hasIoping ? pmssSystemStatsIopingMs('/home') : 'na',
        'topMem'      => pmssSystemStatsTopMemoryProcesses(),
        'psiIo'       => pmssSystemStatsPsiRead('/proc/pressure/io'),
        'psiMem'      => pmssSystemStatsPsiRead('/proc/pressure/memory'),
    ];
}
