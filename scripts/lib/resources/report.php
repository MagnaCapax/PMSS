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
    $windowMetricConfig = [
        'io_read' => false,
        'io_write' => false,
        'io_read_ops' => true,
        'io_write_ops' => true,
        'cpu' => false,
        'ram_hours' => false,
    ];
    $totals = [
        'memory_current' => 0.0,
        'memory_avg_month' => 0.0,
        'tasks_current' => 0.0,
    ];
    foreach ($windowMetricConfig as $metric => $_allowMissing) {
        $totals[$metric] = array_fill_keys($windows, 0.0);
    }

    foreach ($users as $thisUser) {
        $statsPath = "{$statsDir}/{$thisUser}";
        $rawStats = is_file($statsPath) ? @file_get_contents($statsPath) : false;
        if (!is_string($rawStats) || $rawStats === '' || !is_array($data = @unserialize($rawStats))) {
            $missingStats[] = $thisUser;
            continue;
        }

        $windowMetrics = [];
        foreach ($windowMetricConfig as $metric => $allowMissing) {
            $selected = [];
            $rawMetric = $data[$metric]['raw'] ?? [];
            foreach ($windows as $label) {
                if (!isset($rawMetric[$label])) {
                    if (!$allowMissing) {
                        $missingStats[] = $thisUser;
                        continue 3;
                    }
                    $selected[$label] = 0.0;
                    continue;
                }
                $selected[$label] = (float) $rawMetric[$label];
            }
            $windowMetrics[$metric] = $selected;
        }

        $memoryCurrent = (float) ($data['memory']['current'] ?? 0.0);
        $memoryAvgMonth = (float) ($data['memory']['raw']['month'] ?? 0.0);
        $tasksCurrent = (float) ($data['tasks']['current'] ?? 0.0);

        foreach ($windows as $label) {
            foreach ($windowMetrics as $metric => $values) {
                $totals[$metric][$label] += $values[$label];
            }
        }
        $totals['memory_current'] += $memoryCurrent;
        $totals['memory_avg_month'] += $memoryAvgMonth;
        $totals['tasks_current'] += $tasksCurrent;

        $rows[$thisUser] = $windowMetrics + [
            'memory_current' => $memoryCurrent,
            'memory_avg_month' => $memoryAvgMonth,
            'tasks_current' => $tasksCurrent,
        ];
    }

    return [
        'rows' => $rows,
        'missing' => $missingStats,
        'totals' => $totals,
    ];
}

function pmssResourceBuildJsonPayload(array $rows, array $totals, array $missing): array
{
    $payloads = [];
    $windowMetricKeys = ['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu'];
    $sources = $rows;
    $sources['__totals__'] = $totals;
    foreach ($sources as $sourceKey => $source) {
        $payload = [];
        foreach ($windowMetricKeys as $metric) {
            $payload[$metric] = $source[$metric];
        }
        $payload['memory'] = [
            'current' => $source['memory_current'],
            'avg_month' => $source['memory_avg_month'],
        ];
        $payload['ram_hours'] = $source['ram_hours'];
        $payload['tasks'] = [
            'current' => $source['tasks_current'],
        ];
        $payloads[$sourceKey] = $payload;
    }

    $totalPayload = $payloads['__totals__'];
    unset($payloads['__totals__']);

    return [
        'users' => $payloads,
        'totals' => $totalPayload,
        'missing' => $missing,
    ];
}
