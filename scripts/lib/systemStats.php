<?php
/**
 * System stats snapshot helpers for cron logging.
 *
 * @license GPL-3.0-only
 * @author  PMSS Team
 */

require_once __DIR__.'/runtime.php';

/** Convert KiB counters to the compact legacy units used by stats logs. */
function pmssSystemStatsKbToHuman(int $kb): string
{
    foreach ([1048576 => 'G', 1024 => 'M'] as $divisor => $suffix) {
        if ($kb >= $divisor) return number_format($kb / $divisor, 1, '.', '').$suffix;
    }
    return $kb.'K';
}

/**
 * Render validated `ps` top-memory rows for the stats snapshot.
 *
 * @param array<int, string> $rows
 */
function pmssSystemStatsTopMemoryFromPsRows(array $rows): string
{
    $items = [];
    foreach ($rows as $line) {
        $parts = preg_split('/\s+/', trim((string) $line));
        if (!is_array($parts) || count($parts) < 2) {
            continue;
        }

        $command = (string) $parts[0];
        $rssKiB = (string) $parts[1];
        if ($command === '' || preg_match('/[\s,:[:cntrl:]]/', $command) === 1 || !ctype_digit($rssKiB)) {
            continue;
        }

        $items[] = $command.':'.pmssSystemStatsKbToHuman((int) $rssKiB);
    }

    return empty($items) ? 'na' : implode(',', $items);
}

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

/**
 * Render the three load averages from /proc/loadavg after boundary checks.
 */
function pmssSystemStatsLoadAverageFromRaw(?string $raw): string
{
    if ($raw === null) return 'na,na,na';

    $parts = preg_split('/\s+/', trim($raw));
    if (!is_array($parts) || count($parts) < 3) return 'na,na,na';

    $load = array_slice($parts, 0, 3);
    foreach ($load as $value) {
        $value = (string) $value;
        if (strlen($value) > 16 || preg_match('/^\d+(?:\.\d+)?$/', $value) !== 1) {
            return 'na,na,na';
        }
    }

    return implode(',', $load);
}

/** Read the raw CPU tick counters from /proc/stat. */
function pmssSystemStatsCpuCountersRead(): array
{
    $lines = preg_split('/\r?\n/', pmssReadRegularFileContents('/proc/stat') ?? '');
    $parts = is_array($lines) && isset($lines[0]) ? preg_split('/\s+/', trim((string) $lines[0])) : false;
    if (!is_array($parts) || count($parts) < 2) return [];
    array_shift($parts);
    return array_map('intval', $parts);
}

/** Format CPU iowait percentage from two /proc/stat samples. */
function pmssSystemStatsCpuIowaitPercent(array $before, array $after): string
{
    $diff = [];
    foreach ($before as $index => $value) {
        $diff[$index] = max(0, ($after[$index] ?? 0) - $value);
    }

    $total = array_sum($diff);
    return $total > 0 ? number_format((($diff[4] ?? 0) / $total) * 100, 1, '.', '') : '0.0';
}

/** Read per-device busy-time counters from /proc/diskstats. */
function pmssSystemStatsDiskIoTimeRead(): array
{
    $stats = [];
    foreach (preg_split('/\r?\n/', pmssReadRegularFileContents('/proc/diskstats') ?? '') ?: [] as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (!is_array($parts) || count($parts) < 13) continue;
        $name = $parts[2] ?? '';
        if (pmssBlockDeviceNameIsDataDevice($name)) {
            $stats[$name] = (int) ($parts[12] ?? 0);
        }
    }
    return $stats;
}

/** Format the busiest data-device percentage from two diskstats samples. */
function pmssSystemStatsDiskBusyPercent(array $before, array $after, float $sampleSeconds): string
{
    $maxPct = 0.0;
    foreach ($after as $name => $ioTime) {
        $delta = max(0, $ioTime - ($before[$name] ?? $ioTime));
        // /proc/diskstats io_time is in milliseconds spent doing IO.
        $pct = $sampleSeconds > 0 ? ($delta / ($sampleSeconds * 1000)) * 100 : 0.0;
        $maxPct = max($maxPct, $pct);
    }
    return number_format(min(100, $maxPct), 1, '.', '');
}

/** Parse PSI content into the append-only slash-joined stats log field. */
function pmssSystemStatsPsiFromRaw(?string $raw): string
{
    if ($raw === null) return 'na';

    $fields = [
        ['some', 'avg10', 1], ['some', 'avg60', 1], ['full', 'avg10', 1],
        ['some', 'avg300', 1], ['full', 'avg60', 1], ['full', 'avg300', 1],
        ['some', 'total', 0], ['full', 'total', 0],
    ];
    $values = [];
    foreach ($fields as $field) {
        list($row, $name, $decimals) = $field;
        if (!preg_match('/^'.preg_quote($row, '/').'\s.*?'.preg_quote($name, '/').'=([0-9.]+)/m', $raw, $match)) {
            if ($row === 'some' && $name === 'avg10') return 'na';
            $values[] = 'na';
            continue;
        }
        $values[] = number_format((float) $match[1], (int) $decimals, $decimals > 0 ? '.' : '', '');
    }
    return implode('/', $values);
}

/** Read one PSI file and degrade missing/unreadable hosts to `na`. */
function pmssSystemStatsPsiRead(string $path): string { return is_readable($path) ? pmssSystemStatsPsiFromRaw(pmssReadRegularFileContents($path)) : 'na'; }

/** Format optional ioping latency for the stats log. */
function pmssSystemStatsIopingMs(string $path): string { $value = is_dir($path) ? pmssIopingAverageMs($path) : null; return $value === null ? 'na' : number_format($value, 1, '.', '').'ms'; }

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

/**
 * Collect a single snapshot of system metrics for logging.
 *
 * @return array<string, string> Metric values formatted for log output.
 */
function pmssSystemStatsCollect(): array
{
    // Keep this low in test mode so hermetic tests don't waste time sleeping.
    $sampleUsec = pmssTestModeEnabled() ? 50000 : 1000000;

    $cpu1 = pmssSystemStatsCpuCountersRead();
    $disk1 = pmssSystemStatsDiskIoTimeRead();
    usleep($sampleUsec);
    $cpu2 = pmssSystemStatsCpuCountersRead();
    $disk2 = pmssSystemStatsDiskIoTimeRead();

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
