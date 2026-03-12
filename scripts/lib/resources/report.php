<?php
/**
 * Build reporting data for resource usage summaries.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Assemble per-user rows, totals, and missing entries.
 *
 * @return array{rows:array,missing:array,totals:array}
 */
function pmssResourceBuildReport(string $statsDir, array $users): array
{
    $missingStats = [];
    $rows = [];

    $windows = ['month', 'week', 'day', 'hour'];
    $windowZeros = array_fill_keys($windows, 0.0);
    $windowMetricConfig = [
        'io_read' => false,
        'io_write' => false,
        'io_read_ops' => true,
        'io_write_ops' => true,
        'cpu' => false,
        'ram_hours' => false,
    ];
    $totals = array_fill_keys(['memory_current', 'memory_avg_month', 'tasks_current'], 0.0)
        + array_fill_keys(array_keys($windowMetricConfig), $windowZeros);

    foreach ($users as $thisUser) {
        $statsPath = "{$statsDir}/{$thisUser}";
        $rawStats = is_file($statsPath) ? @file_get_contents($statsPath) : false;
        if (!is_string($rawStats) || $rawStats === '' || !is_array($data = @unserialize($rawStats))) {
            $missingStats[] = $thisUser;
            continue;
        }

        $windowMetrics = [];
        foreach ($windowMetricConfig as $metric => $allowMissing) {
            $rawMetric = $data[$metric]['raw'] ?? [];
            $metricValues = $windowZeros;
            foreach ($windows as $label) {
                $value = $rawMetric[$label] ?? null;
                if ($value === null && !$allowMissing) {
                    $missingStats[] = $thisUser;
                    continue 3;
                }
                $metricValues[$label] = (float) ($value ?? 0.0);
            }
            $windowMetrics[$metric] = $metricValues;
        }

        $memoryCurrent = (float) ($data['memory']['current'] ?? 0.0);
        $memoryAvgMonth = (float) ($data['memory']['raw']['month'] ?? 0.0);
        $tasksCurrent = (float) ($data['tasks']['current'] ?? 0.0);

        foreach ($windowMetrics as $metric => $values) {
            foreach ($values as $label => $value) {
                $totals[$metric][$label] += $value;
            }
        }
        $totals['memory_current'] += $memoryCurrent;
        $totals['memory_avg_month'] += $memoryAvgMonth;
        $totals['tasks_current'] += $tasksCurrent;

        $rows[$thisUser] = $windowMetrics + ['memory_current' => $memoryCurrent, 'memory_avg_month' => $memoryAvgMonth, 'tasks_current' => $tasksCurrent];
    }

    return [
        'rows' => $rows,
        'missing' => $missingStats,
        'totals' => $totals,
    ];
}

function pmssResourceBuildJsonPayload(array $rows, array $totals, array $missing): array
{
    $buildPayload = static function (array $source): array {
        $payload = [];
        foreach (['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu', 'ram_hours'] as $metric) {
            $payload[$metric] = $source[$metric];
        }
        $payload['memory'] = [
            'current' => $source['memory_current'],
            'avg_month' => $source['memory_avg_month'],
        ];
        $payload['tasks'] = ['current' => $source['tasks_current']];

        return $payload;
    };

    return [
        'users' => array_map($buildPayload, $rows),
        'totals' => $buildPayload($totals),
        'missing' => $missing,
    ];
}
