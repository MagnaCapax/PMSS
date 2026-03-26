<?php
/**
 * System stats snapshot helpers for cron logging.
 *
 * @license GPL-3.0-only
 * @author  PMSS Team
 */

require_once __DIR__.'/runtime.php';

/**
 * Collect a single snapshot of system metrics for logging.
 *
 * @return array<string, string> Metric values formatted for log output.
 */
function pmssSystemStatsCollect(): array
{
    // These routines only serve the snapshot collector, so keep them local and
    // avoid exporting extra global helpers from the cron logging path.
    $readCpuStat = static function (): array {
        $lines = @file('/proc/stat');
        if (!is_array($lines) || !isset($lines[0])) {
            return [];
        }
        $parts = preg_split('/\s+/', trim((string) $lines[0]));
        if (!is_array($parts) || count($parts) < 2) {
            return [];
        }
        array_shift($parts);
        return array_map('intval', $parts);
    };
    $readDiskIoTime = static function (): array {
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
            if (!preg_match('/^(sd[a-z]+|vd[a-z]+|xvd[a-z]+|nvme\d+n\d+|mmcblk\d+)$/', $name)) {
                continue;
            }
            $stats[$name] = (int) ($parts[12] ?? 0);
        }
        return $stats;
    };
    $kbToHuman = static function (int $kb): string {
        if ($kb >= 1048576) {
            return number_format($kb / 1048576, 1, '.', '').'G';
        }
        if ($kb >= 1024) {
            return number_format($kb / 1024, 1, '.', '').'M';
        }
        return $kb.'K';
    };
    $readPsiAvg10 = static function (string $path): string {
        if (!is_readable($path)) {
            return 'na';
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || !preg_match('/avg10=([0-9.]+)/', $raw, $matches)) {
            return 'na';
        }
        return number_format((float) $matches[1], 1, '.', '');
    };
    $iopingMs = static function (string $path): string {
        if (!is_dir($path)) {
            return 'na';
        }
        $out = trim((string) @shell_exec('ioping -c 1 -q '.escapeshellarg($path).' 2>/dev/null'));
        if ($out === '' || !preg_match('/(?:time=)?([0-9.]+)\s*(ms|us)\b/', $out, $matches)) {
            return 'na';
        }
        $value = (float) $matches[1];
        if ($matches[2] === 'us') {
            $value /= 1000;
        }
        return number_format($value, 1, '.', '').'ms';
    };

    // Keep this low in test mode so hermetic tests don't waste time sleeping.
    $sampleUsec = getenv('PMSS_TEST_MODE') === '1' ? 50000 : 1000000;
    $sampleSeconds = $sampleUsec / 1000000;

    $cpu1 = $readCpuStat();
    $disk1 = $readDiskIoTime();
    usleep($sampleUsec);
    $cpu2 = $readCpuStat();
    $disk2 = $readDiskIoTime();

    $cpuDiff = [];
    foreach ($cpu1 as $i => $val) {
        $cpuDiff[$i] = max(0, ($cpu2[$i] ?? 0) - $val);
    }
    $cpuTotal = array_sum($cpuDiff);
    $cpuIowait = $cpuTotal > 0
        ? number_format((($cpuDiff[4] ?? 0) / $cpuTotal) * 100, 1, '.', '')
        : '0.0';

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

    $load = ['na', 'na', 'na'];
    if (
        is_string($loadRaw = @file_get_contents('/proc/loadavg'))
        && is_array($parts = preg_split('/\s+/', trim($loadRaw)))
        && count($parts) >= 3
    ) {
        $load = array_slice($parts, 0, 3);
    }

    $meminfo = [];
    if (is_array($memLines = @file('/proc/meminfo'))) {
        foreach ($memLines as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                $meminfo[$m[1]] = (int)$m[2];
            }
        }
    }

    $hasIoping = pmssCommandPath('ioping') !== '';
    $iopingRoot = $hasIoping ? $iopingMs('/') : 'na';
    $iopingHome = $hasIoping ? $iopingMs('/home') : 'na';
    $topMem = 'na';
    if (($out = trim((string)@shell_exec('ps -eo comm=,rss= --sort=-rss | head -n 3'))) !== '') {
        $items = [];
        foreach (preg_split('/\n+/', $out) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 2) {
                continue;
            }
            $items[] = $parts[0].':'.$kbToHuman((int) $parts[1]);
        }
        $topMem = empty($items) ? 'na' : implode(',', $items);
    }

    return [
        'load'       => implode(',', $load),
        'cpuIowait'   => $cpuIowait,
        'memTotal'    => $kbToHuman($meminfo['MemTotal'] ?? 0),
        'memFree'     => $kbToHuman($meminfo['MemFree'] ?? 0),
        'memCache'    => $kbToHuman($meminfo['Cached'] ?? 0),
        'memBuffers'  => $kbToHuman($meminfo['Buffers'] ?? 0),
        'swapTotal'   => $kbToHuman($meminfo['SwapTotal'] ?? 0),
        'swapFree'    => $kbToHuman($meminfo['SwapFree'] ?? 0),
        'diskBusy'    => $diskBusy,
        'iopingRoot'  => $iopingRoot,
        'iopingHome'  => $iopingHome,
        'topMem'      => $topMem,
        'psiIo'       => $readPsiAvg10('/proc/pressure/io'),
        'psiMem'      => $readPsiAvg10('/proc/pressure/memory'),
    ];
}
