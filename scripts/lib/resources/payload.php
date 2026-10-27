<?php
/** Resource payload projections shared by persisted reports and raw-log fallbacks. */
require_once __DIR__.'/accumulator.php';
require_once dirname(__DIR__).'/runtime/environment.php';

/** Return the shared schema used by resource rows and totals. */
function pmssResourceReportTemplate(): array
{
    return array_fill_keys(ResourceStatsAccumulator::RAW_METRICS, array_fill_keys(['month', 'week', 'day', 'hour'], 0.0))
        + ['memory' => ['current' => 0.0, 'avg_month' => 0.0], 'tasks' => ['current' => 0.0]];
}

/** Normalize a stored metric window, rejecting malformed values and defaulting missing ops to zero. */
function pmssResourceStoredPayloadWindowValue(array $data, string $metric, string $window): ?float
{
    // A malformed container is not a missing legacy counter, including decoded objects.
    if ((isset($data[$metric]) && !is_array($data[$metric]))
        || (isset($data[$metric]['raw']) && !is_array($data[$metric]['raw']))) return null;
    // Numeric strings can overflow on conversion; serialized floats can be INF/NAN.
    return pmssFiniteFloat($data[$metric]['raw'][$window] ?? (substr($metric, -4) === '_ops' ? 0.0 : null));
}

/** Read all metrics for a stored payload window. */
function pmssResourceStoredPayloadWindowMetrics(array $data, string $window): ?array
{
    $metrics = [];
    foreach (array_merge(ResourceStatsAccumulator::RAW_METRICS, ResourceStatsAccumulator::AVERAGE_METRICS) as $key) {
        if (($metrics[$key] = pmssResourceStoredPayloadWindowValue($data, $key, $window)) === null) return null;
    }
    return $metrics;
}

/** Normalize persisted resource stats into the row shape used by reports. */
function pmssResourceStoredPayloadReportRow(array $data): ?array
{
    $row = pmssResourceReportTemplate();
    foreach (ResourceStatsAccumulator::RAW_METRICS as $metric) {
        foreach (array_keys($row[$metric]) as $label) {
            if (($row[$metric][$label] = pmssResourceStoredPayloadWindowValue($data, $metric, $label)) === null) return null;
        }
    }

    // Check containers before indexing; keep legacy optional-reading casts and defaults.
    foreach (ResourceStatsAccumulator::AVERAGE_METRICS as $metric) {
        if (isset($data[$metric]) && !is_array($data[$metric])) return null;
    }
    if (isset($data['memory']['raw']) && !is_array($data['memory']['raw'])) return null;
    $row['memory']['current'] = (float) ($data['memory']['current'] ?? 0.0);
    $row['memory']['avg_month'] = (float) ($data['memory']['raw']['month'] ?? 0.0);
    $row['tasks']['current'] = (float) ($data['tasks']['current'] ?? 0.0);
    return $row;
}
