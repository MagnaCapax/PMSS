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
    $missingStats = $rows = [];
    $windows = ['month', 'week', 'day', 'hour'];
    $metrics = ['io_read', 'io_write', 'cpu', 'ram_hours', 'io_read_ops', 'io_write_ops'];
    $windowZeros = array_fill_keys($windows, 0.0);
    $totals = array_fill_keys($metrics, $windowZeros) + array_fill_keys(['memory_current', 'memory_avg_month', 'tasks_current'], 0.0);

    foreach ($users as $thisUser) {
        $statsPath = "{$statsDir}/{$thisUser}";
        $rawStats = is_file($statsPath) ? @file_get_contents($statsPath) : false;
        if (!is_string($rawStats) || $rawStats === '' || !is_array($data = @unserialize($rawStats))) {
            $missingStats[] = $thisUser;
            continue;
        }

        $windowMetrics = [];
        foreach ($metrics as $metric) {
            $rawMetric = $data[$metric]['raw'] ?? [];
            $metricValues = $windowZeros;
            foreach ($windows as $label) {
                $value = $rawMetric[$label] ?? null;
                if ($value === null && substr($metric, -4) !== '_ops') {
                    $missingStats[] = $thisUser;
                    continue 3;
                }
                $metricValues[$label] = (float) ($value ?? 0.0);
            }
            $windowMetrics[$metric] = $metricValues;
        }

        $summary = [
            'memory_current' => (float) ($data['memory']['current'] ?? 0.0),
            'memory_avg_month' => (float) ($data['memory']['raw']['month'] ?? 0.0),
            'tasks_current' => (float) ($data['tasks']['current'] ?? 0.0),
        ];

        foreach ($windowMetrics as $metric => $values) {
            foreach ($values as $label => $value) {
                $totals[$metric][$label] += $value;
            }
        }
        foreach ($summary as $metric => $value) {
            $totals[$metric] += $value;
        }

        $rows[$thisUser] = $windowMetrics + $summary;
    }

    return ['rows' => $rows, 'missing' => $missingStats, 'totals' => $totals];
}
