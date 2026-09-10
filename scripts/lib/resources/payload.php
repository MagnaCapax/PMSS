<?php
/** Resource payload projections shared by persisted reports and raw-log fallbacks. */
require_once __DIR__.'/accumulator.php';

/** Return the shared schema used by resource rows and totals. */
function pmssResourceReportTemplate(): array
{
    $windows = array_fill_keys(['month', 'week', 'day', 'hour'], 0.0);
    return array_fill_keys(ResourceStatsAccumulator::RAW_METRICS, $windows) + ['memory' => ['current' => 0.0, 'avg_month' => 0.0], 'tasks' => ['current' => 0.0]];
}

/** Normalize scalar metric values while rejecting malformed persisted/fallback data. */
function pmssResourceMetricValueNormalize($value): ?float { return is_numeric($value) ? (float) $value : null; }

/** Return metrics shared by resource snapshot rows and fallback calculations. */
function pmssResourceSnapshotMetricKeys(): array { return array_merge(ResourceStatsAccumulator::RAW_METRICS, ResourceStatsAccumulator::AVERAGE_METRICS); }

/** Read a stored payload metric window, defaulting missing ops windows to zero. */
function pmssResourceStoredPayloadWindowValue(array $data, string $metric, string $window): ?float
{
    $value = $data[$metric]['raw'][$window] ?? (substr($metric, -4) === '_ops' ? 0.0 : null);
    return $value !== null ? pmssResourceMetricValueNormalize($value) : null;
}

/** Read all metrics for a stored payload window. */
function pmssResourceStoredPayloadWindowMetrics(array $data, string $window): ?array
{
    $metrics = [];
    foreach (pmssResourceSnapshotMetricKeys() as $key) {
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
