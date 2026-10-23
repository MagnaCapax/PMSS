<?php
/** Customer Info statistics: statsResourceModel. Loaded by statsHelpers.php. */

/**
 * Build the resource display model consumed by the resource and I/O blocks.
 */
function pmssStatsResourceSnapshotBuild(?array $resourceData): array
{
    $displayFields = array('io_read' => 'ioReadDisplay', 'io_write' => 'ioWriteDisplay', 'cpu' => 'cpuDisplay', 'memory' => 'memoryDisplay', 'ram_hours' => 'ramHoursDisplay');
    $snapshot = array_fill_keys(array_values($displayFields), array())
        + array_fill_keys(array('memoryCurrent', 'memoryAnon', 'memoryFile', 'tasksCurrent'), 'n/a')
        + array('ioOperationsMonth' => 0.0)
        + array_fill_keys(array('ioDailyLabels', 'ioDailyRead', 'ioDailyWrite', 'ioDailyOperations', 'cpuDailyHours'), array());
    if ($resourceData === null) {
        return $snapshot;
    }

    // Saved display values win; only RAM-hours use a different numeric fallback.
    foreach ($displayFields as $section => $target) {
        $snapshot[$target] = pmssStatsNestedArrayRead($resourceData, $section, 'display');
        if ($section === 'cpu') continue;
        $raw = pmssStatsNestedArrayRead($resourceData, $section, 'raw');
        foreach (array('month', 'week', 'day', 'hour') as $period) {
            if (!isset($snapshot[$target][$period]) && is_numeric($raw[$period] ?? null)) {
                $snapshot[$target][$period] = $section === 'ram_hours'
                    ? round((float) $raw[$period], 2).'GB-hrs' : pmssFormatBytesShort($raw[$period]);
            }
        }
    }
    $cpuRaw = pmssStatsNestedArrayRead($resourceData, 'cpu', 'raw');
    $snapshot['ioOperationsMonth'] = (float)(pmssStatsNestedArrayRead($resourceData, 'io_read_ops', 'raw')['month'] ?? 0.0)
        + (float)(pmssStatsNestedArrayRead($resourceData, 'io_write_ops', 'raw')['month'] ?? 0.0);
    // Monthly CPU nanoseconds are always displayed as CPU-hours.
    if (isset($cpuRaw['month'])) { $snapshot['cpuDisplay']['month'] = round(((float) $cpuRaw['month'] / 1000000000) / 3600, 2).' CPU-hours'; }
    foreach (array('week', 'day', 'hour') as $period) {
        if (!isset($snapshot['cpuDisplay'][$period]) && isset($cpuRaw[$period])) {
            $snapshot['cpuDisplay'][$period] = pmssFormatDurationSeconds($cpuRaw[$period] / 1000000000);
        }
    }
    foreach (array('current' => 'memoryCurrent', 'anon' => 'memoryAnon', 'file' => 'memoryFile') as $source => $target) {
        if (isset($resourceData['memory'][$source])) {
            $snapshot[$target] = pmssFormatBytesShort($resourceData['memory'][$source]);
        }
    }
    if (isset($resourceData['tasks']['current'])) { $snapshot['tasksCurrent'] = (string) round((float)$resourceData['tasks']['current'], 2); }

    if (is_array($resourceData['daily'] ?? null)) {
        $dailySeries = array('ioDailyRead' => array('io_read', 1048576, 2), 'ioDailyWrite' => array('io_write', 1048576, 2), 'cpuDailyHours' => array('cpu', 3600000000000, 4));
        foreach ($resourceData['daily'] as $day => $totals) {
            $snapshot['ioDailyLabels'][] = $day;
            foreach ($dailySeries as $target => $series) {
                $snapshot[$target][] = round((float)($totals[$series[0]] ?? 0.0) / $series[1], $series[2]);
            }
            $snapshot['ioDailyOperations'][] = round((float)($totals['io_read_ops'] ?? 0.0) + (float)($totals['io_write_ops'] ?? 0.0), 2);
        }
    }

    return $snapshot;
}

/** Return a nested array section or an empty fallback. */
function pmssStatsNestedArrayRead(array $source, string $section, string $key): array
{
    return is_array($source[$section][$key] ?? null) ? $source[$section][$key] : array();
}

/** Format a byte count for compact customer stats labels. */
function pmssFormatBytesShort($bytes): string
{
    $bytes = (float) $bytes;
    foreach (array(1099511627776 => 'TiB', 1073741824 => 'GiB', 1048576 => 'MiB') as $divisor => $unit) {
        if (($bytes / $divisor) > 1) {
            return round($bytes / $divisor, 2).$unit;
        }
    }

    return round($bytes / 1024, 2).'KiB';
}

/** Format short elapsed durations for resource summaries. */
function pmssFormatDurationSeconds($seconds): string
{
    $seconds = (float) $seconds;
    if ($seconds >= 3600) {
        return round($seconds / 3600, 2).'h';
    }
    if ($seconds >= 60) {
        return round($seconds / 60, 2).'m';
    }
    return round($seconds, 2).'s';
}

/** Format an I/O operation count for compact display. */
function pmssFormatIoOperationsShort($operations): string
{
    $operations = max(0.0, (float) $operations);
    foreach (array(1000000000.0 => 'billion', 1000000.0 => 'million', 1000.0 => 'thousand') as $divisor => $unit) {
        if ($operations >= $divisor) {
            $value = $operations / $divisor;
            $decimals = $value >= 100 ? 0 : ($value >= 10 ? 1 : 2);
            return number_format($value, $decimals).' '.$unit.' IO operations';
        }
    }

    return number_format($operations, 0).' IO operations';
}
