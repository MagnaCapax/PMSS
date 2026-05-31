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
        $lines = preg_split('/\r?\n/', pmssReadRegularFileContents('/proc/stat') ?? '');
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
        foreach (preg_split('/\r?\n/', pmssReadRegularFileContents('/proc/diskstats') ?? '') ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (!is_array($parts) || count($parts) < 13) {
                continue;
            }
            $name = $parts[2] ?? '';
            if (!pmssBlockDeviceNameIsDataDevice($name)) {
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
    $readPsi = static function (string $path): string {
        // /proc/pressure/io and /proc/pressure/memory expose:
        //   some avg10=X.XX avg60=X.XX avg300=X.XX total=N
        //   full avg10=X.XX avg60=X.XX avg300=X.XX total=N
        // Emit ALL of it as one slash-joined field (append-only; first three
        // indices preserve the legacy tools/fleet/node-collect parser contract
        // some_avg10/some_avg60/full_avg10). `full` is the actionable signal.
        if (!is_readable($path)) {
            return 'na';
        }
        $raw = pmssReadRegularFileContents($path);
        if ($raw === null) {
            return 'na';
        }
        $find = static function (string $row, string $field) use ($raw): ?float {
            if (!preg_match('/^'.preg_quote($row, '/').'\s.*?'.preg_quote($field, '/').'=([0-9.]+)/m', $raw, $m)) {
                return null;
            }
            return (float) $m[1];
        };
        if (($someAvg10 = $find('some', 'avg10')) === null) {
            return 'na';
        }
        // Append-only field order. The first three fields are the legacy
        // node-collect parser contract and MUST stay byte-identical for
        // backward compatibility; everything after is appended:
        //   some_avg10/some_avg60/full_avg10/some_avg300/full_avg60/full_avg300/some_total/full_total
        // `full` (all non-idle tasks stalled) is the actionable I/O signal —
        // psi-notify's default alert is io.full.avg10 >= 15. The *_total fields
        // are cumulative microseconds stalled since boot; downstream consumers
        // delta them over the collection interval for a lossless, cadence-immune
        // average (the kernel avgN windows are point samples).
        $fmtAvg = static function (?float $v): string {
            return $v === null ? 'na' : number_format($v, 1, '.', '');
        };
        $fmtTotal = static function (?float $v): string {
            return $v === null ? 'na' : number_format($v, 0, '', '');
        };
        return implode('/', [
            $fmtAvg($someAvg10),
            $fmtAvg($find('some', 'avg60')),
            $fmtAvg($find('full', 'avg10')),
            $fmtAvg($find('some', 'avg300')),
            $fmtAvg($find('full', 'avg60')),
            $fmtAvg($find('full', 'avg300')),
            $fmtTotal($find('some', 'total')),
            $fmtTotal($find('full', 'total')),
        ]);
    };
    $iopingMs = static function (string $path): string { $value = is_dir($path) ? pmssIopingAverageMs($path) : null; return $value === null ? 'na' : number_format($value, 1, '.', '').'ms'; };

    // Keep this low in test mode so hermetic tests don't waste time sleeping.
    $sampleUsec = pmssTestModeEnabled() ? 50000 : 1000000;
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
        is_string($loadRaw = pmssReadRegularFileContents('/proc/loadavg'))
        && is_array($parts = preg_split('/\s+/', trim($loadRaw)))
        && count($parts) >= 3
    ) {
        $load = array_slice($parts, 0, 3);
    }

    $meminfo = pmssProcMeminfoFieldsRead();

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
        'psiIo'       => $readPsi('/proc/pressure/io'),
        'psiMem'      => $readPsi('/proc/pressure/memory'),
    ];
}
