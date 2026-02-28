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
    $totals = [
        'io_read' => array_fill_keys($windows, 0.0),
        'io_write' => array_fill_keys($windows, 0.0),
        'io_read_ops' => array_fill_keys($windows, 0.0),
        'io_write_ops' => array_fill_keys($windows, 0.0),
        'cpu' => array_fill_keys($windows, 0.0),
        'ram_hours' => array_fill_keys($windows, 0.0),
        'memory_current' => 0.0,
        'memory_avg_month' => 0.0,
        'tasks_current' => 0.0,
    ];

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
        foreach (['io_read', 'io_write', 'cpu', 'ram_hours'] as $metric) {
            $windowMetrics[$metric] = $selectWindows($data[$metric]['raw'] ?? []);
            if ($windowMetrics[$metric] === null) {
                $missingStats[] = $thisUser;
                continue 2;
            }
        }
        foreach (['io_read_ops', 'io_write_ops'] as $metric) {
            $windowMetrics[$metric] = $selectWindows($data[$metric]['raw'] ?? [], true);
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
    foreach ($rows as $username => $row) {
        $users[$username] = [
            'io_read' => $row['io_read'],
            'io_write' => $row['io_write'],
            'io_read_ops' => $row['io_read_ops'],
            'io_write_ops' => $row['io_write_ops'],
            'cpu' => $row['cpu'],
            'memory' => [
                'current' => $row['memory_current'],
                'avg_month' => $row['memory_avg_month'],
            ],
            'ram_hours' => $row['ram_hours'],
            'tasks' => [
                'current' => $row['tasks_current'],
            ],
        ];
    }

    return [
        'users' => $users,
        'totals' => [
            'io_read' => $totals['io_read'],
            'io_write' => $totals['io_write'],
            'io_read_ops' => $totals['io_read_ops'],
            'io_write_ops' => $totals['io_write_ops'],
            'cpu' => $totals['cpu'],
            'memory' => [
                'current' => $totals['memory_current'],
                'avg_month' => $totals['memory_avg_month'],
            ],
            'ram_hours' => $totals['ram_hours'],
            'tasks' => [
                'current' => $totals['tasks_current'],
            ],
        ],
        'missing' => $missing,
    ];
}
