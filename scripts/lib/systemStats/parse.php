<?php
/** Pure host-statistics parsing and legacy log projection. @license GPL-3.0-only */
require_once dirname(__DIR__).'/runtime.php';

/** Accept digit counters only when PHP can represent their exact value. */
function pmssSystemStatsCounterIsValid(string $value): bool
{
    return pmssUnsignedDecimalIntParse($value) !== null;
}

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
    foreach ($rows as $row) {
        // Reject raw NULs before column trimming can hide malformed probe output.
        $row = (string) $row;
        if (strpos($row, "\0") !== false || ($parts = pmssConfigLineColumns($row, 2, [])) === []) continue;
        [$command, $rssKiB] = $parts;
        if (preg_match('/[\s,:[:cntrl:]]/', $command) === 1 || ($rssKiB = pmssUnsignedDecimalIntParse($rssKiB)) === null) {
            continue;
        }

        $items[] = $command.':'.pmssSystemStatsKbToHuman($rssKiB);
    }

    return empty($items) ? 'na' : implode(',', $items);
}

/**
 * Render the three load averages from /proc/loadavg after boundary checks.
 */
function pmssSystemStatsLoadAverageFromRaw(?string $raw): string
{
    if ($raw !== null && strpos($raw, "\0") !== false) return 'na,na,na';
    $load = array_slice(pmssConfigLineColumns($raw ?? '', 3, []), 0, 3);
    if ($load === []) return 'na,na,na';
    foreach ($load as $value) {
        $value = (string) $value;
        if (strlen($value) > 16 || preg_match('/^\d+(?:\.\d+)?$/', $value) !== 1) {
            return 'na,na,na';
        }
    }

    return implode(',', $load);
}

/** Parse raw CPU tick counters from /proc/stat after validating the boundary shape. */
function pmssSystemStatsCpuCountersFromRaw(?string $raw): array
{
    if ($raw === null) return [];

    $line = pmssTextLines($raw, 2)[0];
    // Only the aggregate CPU row is consumed; reject its NULs before trimming.
    if (strpos($line, "\0") !== false) return [];
    $parts = pmssConfigLineColumns($line, 6, []);
    if ($parts === [] || array_shift($parts) !== 'cpu') return [];

    $counters = array_map('pmssUnsignedDecimalIntParse', $parts);
    return in_array(null, $counters, true) ? [] : $counters;
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

/** Parse per-device busy-time counters from raw /proc/diskstats content. */
function pmssSystemStatsDiskIoTimeFromRaw(?string $raw): array
{
    $stats = [];
    foreach (pmssTextLines($raw ?? '') as $line) {
        // A malformed device row must not discard other devices in the snapshot.
        if (strpos($line, "\0") !== false || ($parts = pmssConfigLineColumns($line, 13, [])) === []) continue;
        $name = $parts[2];
        if (pmssBlockDeviceNameIsDataDevice($name) && ($ioTime = pmssUnsignedDecimalIntParse($parts[12])) !== null) {
            $stats[$name] = $ioTime;
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
        $maxPct = max($maxPct, $sampleSeconds > 0 ? ($delta / ($sampleSeconds * 1000)) * 100 : 0.0);
    }
    return number_format(min(100, $maxPct), 1, '.', '');
}

/** Read one PSI field with the shared percentage/integer boundary rules. */
function pmssSystemStatsPsiMetricFromRaw(string $raw, string $row, string $name): ?float
{
    if (!preg_match('/^'.preg_quote($row, '/').'\s.*?'.preg_quote($name, '/').'=([0-9]+(?:\.[0-9]+)?)(?=\s|$)/m', $raw, $match)) return null;
    $number = (float) $match[1];
    if ($name === 'total') return pmssUnsignedDecimalIntParse($match[1]) === null ? null : $number;
    return is_finite($number) && $number <= 100 ? $number : null;
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
    foreach ($fields as [$row, $name, $decimals]) {
        $number = pmssSystemStatsPsiMetricFromRaw($raw, $row, $name);
        if ($number === null && $row === 'some' && $name === 'avg10') return 'na';
        $values[] = $number === null ? 'na' : number_format($number, $decimals, '.', '');
    }
    return implode('/', $values);
}

