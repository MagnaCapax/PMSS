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

    $totals = [
        'io_read' => ['month' => 0.0, 'week' => 0.0, 'day' => 0.0, 'hour' => 0.0],
        'io_write' => ['month' => 0.0, 'week' => 0.0, 'day' => 0.0, 'hour' => 0.0],
        'cpu' => ['month' => 0.0, 'week' => 0.0, 'day' => 0.0, 'hour' => 0.0],
        'ram_hours' => ['month' => 0.0, 'week' => 0.0, 'day' => 0.0, 'hour' => 0.0],
        'memory_current' => 0.0,
        'memory_avg_month' => 0.0,
        'tasks_current' => 0.0,
    ];

    foreach ($users as $thisUser) {
        $statsPath = "{$statsDir}/{$thisUser}";
        if (!is_file($statsPath)) {
            $missingStats[] = $thisUser;
            continue;
        }

        $rawStats = @file_get_contents($statsPath);
        if (!is_string($rawStats) || $rawStats === '') {
            $missingStats[] = $thisUser;
            continue;
        }

        $data = @unserialize($rawStats);
        if (!is_array($data)) {
            $missingStats[] = $thisUser;
            continue;
        }

        $ioRead = pmssResourceSelectWindows($data['io_read']['raw'] ?? []);
        $ioWrite = pmssResourceSelectWindows($data['io_write']['raw'] ?? []);
        $cpu = pmssResourceSelectWindows($data['cpu']['raw'] ?? []);
        $ramHours = pmssResourceSelectWindows($data['ram_hours']['raw'] ?? []);
        if ($ioRead === null || $ioWrite === null || $cpu === null || $ramHours === null) {
            $missingStats[] = $thisUser;
            continue;
        }

        $memoryCurrent = isset($data['memory']['current']) ? (float) $data['memory']['current'] : 0.0;
        $memoryAvgMonth = isset($data['memory']['raw']['month']) ? (float) $data['memory']['raw']['month'] : 0.0;
        $tasksCurrent = isset($data['tasks']['current']) ? (float) $data['tasks']['current'] : 0.0;

        foreach (['month', 'week', 'day', 'hour'] as $label) {
            $totals['io_read'][$label] += $ioRead[$label];
            $totals['io_write'][$label] += $ioWrite[$label];
            $totals['cpu'][$label] += $cpu[$label];
            $totals['ram_hours'][$label] += $ramHours[$label];
        }
        $totals['memory_current'] += $memoryCurrent;
        $totals['memory_avg_month'] += $memoryAvgMonth;
        $totals['tasks_current'] += $tasksCurrent;

        $rows[$thisUser] = [
            'io_read' => $ioRead,
            'io_write' => $ioWrite,
            'cpu' => $cpu,
            'memory_current' => $memoryCurrent,
            'memory_avg_month' => $memoryAvgMonth,
            'ram_hours' => $ramHours,
            'tasks_current' => $tasksCurrent,
        ];
    }

    return [
        'rows' => $rows,
        'missing' => $missingStats,
        'totals' => $totals,
    ];
}

function pmssResourceSelectWindows(array $raw): ?array
{
    $labels = ['month', 'week', 'day', 'hour'];
    $selected = [];
    foreach ($labels as $label) {
        if (!isset($raw[$label])) {
            return null;
        }
        $selected[$label] = (float) $raw[$label];
    }
    return $selected;
}

function pmssResourceBuildJsonPayload(array $rows, array $totals, array $missing): array
{
    $users = [];
    foreach ($rows as $username => $row) {
        $users[$username] = [
            'io_read' => $row['io_read'],
            'io_write' => $row['io_write'],
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
