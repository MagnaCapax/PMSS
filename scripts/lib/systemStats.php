<?php
/**
 * System stats snapshot helpers for cron logging.
 *
 * @license GPL-3.0-only
 * @author  PMSS Team
 */

/**
 * Collect a single snapshot of system metrics for logging.
 *
 * @return array<string, string> Metric values formatted for log output.
 */
function pmssSystemStatsCollect(): array
{
    // Keep this low in test mode so hermetic tests don't waste time sleeping.
    $sampleUsec = getenv('PMSS_TEST_MODE') === '1' ? 50000 : 1000000;
    $sampleSeconds = $sampleUsec / 1000000;

    $cpu1 = pmssSystemStatsReadCpuStat();
    $disk1 = pmssSystemStatsReadDiskIoTime();
    usleep($sampleUsec);
    $cpu2 = pmssSystemStatsReadCpuStat();
    $disk2 = pmssSystemStatsReadDiskIoTime();

    $cpuDiff = [];
    foreach ($cpu1 as $i => $val) {
        $cpuDiff[$i] = max(0, ($cpu2[$i] ?? 0) - $val);
    }
    $cpuTotal = array_sum($cpuDiff);
    $cpuIowait = $cpuTotal > 0
        ? number_format((($cpuDiff[4] ?? 0) / $cpuTotal) * 100, 1, '.', '')
        : '0.0';

    $diskBusy = '0.0';
    if ($disk2) {
        $maxPct = 0.0;
        foreach ($disk2 as $name => $ioTime) {
            $delta = max(0, $ioTime - ($disk1[$name] ?? $ioTime));
            // /proc/diskstats io_time is in milliseconds spent doing IO.
            $pct = $sampleSeconds > 0 ? ($delta / ($sampleSeconds * 1000)) * 100 : 0.0;
            if ($pct > $maxPct) {
                $maxPct = $pct;
            }
        }
        $diskBusy = number_format(min(100, $maxPct), 1, '.', '');
    }

    $load = ['na', 'na', 'na'];
    $loadRaw = @file_get_contents('/proc/loadavg');
    if (is_string($loadRaw)) {
        $parts = preg_split('/\s+/', trim($loadRaw));
        if (is_array($parts) && count($parts) >= 3) {
            $load = array_slice($parts, 0, 3);
        }
    }

    $meminfo = [];
    $memLines = @file('/proc/meminfo');
    if (is_array($memLines)) {
        foreach ($memLines as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                $meminfo[$m[1]] = (int)$m[2];
            }
        }
    }

    $memTotal = pmssSystemStatsKbToHuman($meminfo['MemTotal'] ?? 0);
    $memFree = pmssSystemStatsKbToHuman($meminfo['MemFree'] ?? 0);
    $memCache = pmssSystemStatsKbToHuman($meminfo['Cached'] ?? 0);
    $memBuffers = pmssSystemStatsKbToHuman($meminfo['Buffers'] ?? 0);
    $swapTotal = pmssSystemStatsKbToHuman($meminfo['SwapTotal'] ?? 0);
    $swapFree = pmssSystemStatsKbToHuman($meminfo['SwapFree'] ?? 0);
    $psiIo = pmssSystemStatsPsiAvg10('/proc/pressure/io');
    $psiMem = pmssSystemStatsPsiAvg10('/proc/pressure/memory');

    $hasIoping = trim((string)@shell_exec('command -v ioping 2>/dev/null')) !== '';
    $iopingRoot = $hasIoping ? pmssSystemStatsIopingMs('/') : 'na';
    $iopingHome = $hasIoping ? pmssSystemStatsIopingMs('/home') : 'na';

    return [
        'load'       => implode(',', $load),
        'cpuIowait'   => $cpuIowait,
        'memTotal'    => $memTotal,
        'memFree'     => $memFree,
        'memCache'    => $memCache,
        'memBuffers'  => $memBuffers,
        'swapTotal'   => $swapTotal,
        'swapFree'    => $swapFree,
        'diskBusy'    => $diskBusy,
        'iopingRoot'  => $iopingRoot,
        'iopingHome'  => $iopingHome,
        'topMem'      => pmssSystemStatsTopMem(),
        'psiIo'       => $psiIo,
        'psiMem'      => $psiMem,
    ];
}

/**
 * Read CPU counters from /proc/stat for utilization math.
 * @return int[] CPU time counters in jiffies order.
 */
function pmssSystemStatsReadCpuStat(): array
{
    $lines = @file('/proc/stat');
    if (!is_array($lines) || !isset($lines[0])) {
        return [];
    }
    $parts = preg_split('/\s+/', trim((string)$lines[0]));
    if (!is_array($parts) || count($parts) < 2) {
        return [];
    }
    array_shift($parts);
    return array_map('intval', $parts);
}

/**
 * Read disk IO time counters from /proc/diskstats.
 * @return array<string, int> Map of device name to io time.
 */
function pmssSystemStatsReadDiskIoTime(): array
{
    $stats = [];
    $lines = @file('/proc/diskstats');
    if (!is_array($lines)) {
        return $stats;
    }
    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (!is_array($parts) || count($parts) < 13) {
            continue;
        }
        $name = $parts[2] ?? '';
        if ($name === '' || !preg_match('/^(sd[a-z]+|vd[a-z]+|xvd[a-z]+|nvme\d+n\d+|mmcblk\d+)$/', $name)) {
            continue;
        }
        $stats[$name] = (int) ($parts[12] ?? 0);
    }
    return $stats;
}

/**
 * Convert a kB value into a compact human-readable string.
 * @param int $kb Raw kibibyte value from procfs.
 * @return string Value formatted with K/M/G suffix.
 */
function pmssSystemStatsKbToHuman(int $kb): string
{
    if ($kb >= 1048576) {
        return number_format($kb / 1048576, 1, '.', '').'G';
    }
    if ($kb >= 1024) {
        return number_format($kb / 1024, 1, '.', '').'M';
    }
    return $kb.'K';
}
/**
 * Read PSI avg10 value from a pressure file if present.
 * @param string $path Full path to pressure file.
 * @return string avg10 value or "na" if unavailable.
 */
function pmssSystemStatsPsiAvg10(string $path): string
{
    if (!is_readable($path)) {
        return 'na';
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || !preg_match('/avg10=([0-9.]+)/', $raw, $m)) {
        return 'na';
    }
    return number_format((float) $m[1], 1, '.', '');
}

/**
 * Capture a single ioping latency sample for a path.
 * @param string $path Filesystem path to probe.
 * @return string Latency in ms or "na" when missing.
 */
function pmssSystemStatsIopingMs(string $path): string
{
    if (!is_dir($path)) {
        return 'na';
    }
    $out = trim((string)@shell_exec('ioping -c 1 -q '.escapeshellarg($path).' 2>/dev/null'));
    if ($out === '' || !preg_match('/(?:time=)?([0-9.]+)\s*(ms|us)\b/', $out, $m)) {
        return 'na';
    }
    $val = (float)$m[1];
    if ($m[2] === 'us') {
        $val = $val / 1000;
    }
    return number_format($val, 1, '.', '').'ms';
}

/**
 * Format the top three RSS consumers as name:SIZE entries.
 * @return string Comma list of process:memory entries.
 */
function pmssSystemStatsTopMem(): string
{
    $out = trim((string)@shell_exec('ps -eo comm=,rss= --sort=-rss | head -n 3'));
    if ($out === '') {
        return 'na';
    }
    $items = [];
    foreach (preg_split('/\n+/', $out) as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) < 2) {
            continue;
        }
        $items[] = $parts[0].':'.pmssSystemStatsKbToHuman((int) $parts[1]);
    }
    return $items ? implode(',', $items) : 'na';
}
