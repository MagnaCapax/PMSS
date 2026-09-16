<?php
/** Resource payload projections shared by persisted reports and raw-log fallbacks. */
require_once __DIR__.'/accumulator.php';

/** Return the shared schema used by resource rows and totals. */
function pmssResourceReportTemplate(): array
{
    $windows = array_fill_keys(['month', 'week', 'day', 'hour'], 0.0);
    return array_fill_keys(ResourceStatsAccumulator::RAW_METRICS, $windows) + ['memory' => ['current' => 0.0, 'avg_month' => 0.0], 'tasks' => ['current' => 0.0]];
}

/** Normalize a stored metric window, rejecting malformed values and defaulting missing ops to zero. */
function pmssResourceStoredPayloadWindowValue(array $data, string $metric, string $window): ?float
{
    $value = $data[$metric]['raw'][$window] ?? (substr($metric, -4) === '_ops' ? 0.0 : null);
    // Numeric strings can overflow on conversion; serialized floats can be INF/NAN.
    return $value !== null && is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
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

    $row['memory'] = ['current' => (float) ($data['memory']['current'] ?? 0.0), 'avg_month' => (float) ($data['memory']['raw']['month'] ?? 0.0)];
    $row['tasks'] = ['current' => (float) ($data['tasks']['current'] ?? 0.0)];
    return $row;
}
