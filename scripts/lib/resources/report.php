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

    $selectWindows = static function (array $raw, bool $allowMissing = false) use ($windows): ?array {
        $selected = [];
        foreach ($windows as $label) {
            if (!isset($raw[$label])) {
                if ($allowMissing) {
                    $selected[$label] = 0.0;
                    continue;
                }
                return null;
            }
            $selected[$label] = (float) $raw[$label];
        }
        return $selected;
    };

    foreach ($users as $thisUser) {
        $statsPath = "{$statsDir}/{$thisUser}";
        $rawStats = is_file($statsPath) ? @file_get_contents($statsPath) : false;
        if (!is_string($rawStats) || $rawStats === '' || !is_array($data = @unserialize($rawStats))) {
            $missingStats[] = $thisUser;
            continue;
        }

        $windowMetrics = [];
        foreach ($windowMetricConfig as $metric => $allowMissing) {
            $windowMetrics[$metric] = $selectWindows($data[$metric]['raw'] ?? [], $allowMissing);
            if ($windowMetrics[$metric] === null) {
                $missingStats[] = $thisUser;
                continue 2;
            }
        }

        $memoryCurrent = isset($data['memory']['current']) ? (float) $data['memory']['current'] : 0.0;
        $memoryAvgMonth = isset($data['memory']['raw']['month']) ? (float) $data['memory']['raw']['month'] : 0.0;
        $tasksCurrent = isset($data['tasks']['current']) ? (float) $data['tasks']['current'] : 0.0;

        foreach ($windows as $label) {
            foreach ($windowMetrics as $metric => $values) {
                $totals[$metric][$label] += $values[$label];
            }
        }
        $totals['memory_current'] += $memoryCurrent;
        $totals['memory_avg_month'] += $memoryAvgMonth;
        $totals['tasks_current'] += $tasksCurrent;

        $rows[$thisUser] = [
            'io_read' => $windowMetrics['io_read'],
            'io_write' => $windowMetrics['io_write'],
            'io_read_ops' => $windowMetrics['io_read_ops'],
            'io_write_ops' => $windowMetrics['io_write_ops'],
            'cpu' => $windowMetrics['cpu'],
            'memory_current' => $memoryCurrent,
            'memory_avg_month' => $memoryAvgMonth,
            'ram_hours' => $windowMetrics['ram_hours'],
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
    $users = [];
    $windowMetricKeys = ['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu'];
    foreach ($rows as $username => $row) {
        $users[$username] = [];
        foreach ($windowMetricKeys as $metric) {
            $users[$username][$metric] = $row[$metric];
        }
        $users[$username]['memory'] = [
            'current' => $row['memory_current'],
            'avg_month' => $row['memory_avg_month'],
        ];
        $users[$username]['ram_hours'] = $row['ram_hours'];
        $users[$username]['tasks'] = [
            'current' => $row['tasks_current'],
        ];
    }

    $totalPayload = [];
    foreach ($windowMetricKeys as $metric) {
        $totalPayload[$metric] = $totals[$metric];
    }
    $totalPayload['memory'] = [
        'current' => $totals['memory_current'],
        'avg_month' => $totals['memory_avg_month'],
    ];
    $totalPayload['ram_hours'] = $totals['ram_hours'];
    $totalPayload['tasks'] = [
        'current' => $totals['tasks_current'],
    ];

    return [
        'users' => $users,
        'totals' => $totalPayload,
        'missing' => $missing,
    ];
}
