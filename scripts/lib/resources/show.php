<?php
/**
 * CLI helper: show per-user resource usage.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/log.php';
require_once dirname(__DIR__).'/userLifecycle.php';

/** @return string[] */
function pmssResourceMetricNames(): array
{
    return ['io_read', 'io_write', 'cpu', 'ram_hours', 'io_read_ops', 'io_write_ops'];
}

/**
 * Assemble per-user rows, totals, and missing entries.
 *
 * @return array{rows:array,missing:array,totals:array}
 */
function pmssResourceBuildReport(string $statsDir, array $users): array
{
    $missingStats = $rows = [];
    $windows = ['month', 'week', 'day', 'hour'];
    $metrics = pmssResourceMetricNames();
    $windowZeros = array_fill_keys($windows, 0.0);
    $totals = array_fill_keys($metrics, $windowZeros) + array_fill_keys(['memory_current', 'memory_avg_month', 'tasks_current'], 0.0);

    foreach ($users as $thisUser) {
        if (!is_string($rawStats = @file_get_contents("{$statsDir}/{$thisUser}"))
            || $rawStats === ''
            || !is_array($data = @unserialize($rawStats, ['allowed_classes' => false]))) {
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

function pmssShowResourcesMain(array $argv): int
{
    $options = getopt('', ['json', 'show-missing', 'user:', 'help']);
    if (isset($options['help'])) {
        $self = basename($_SERVER['SCRIPT_NAME'] ?? 'showResources.php');
        echo <<<TEXT
Usage: {$self} [--json] [--show-missing] [--user=<username>]

Options:
  --json          Emit JSON instead of human text output.
  --show-missing  Print missing stats usernames (text mode only).
  --user          Show only the named user.
  --help          Show this help.

TEXT;
        echo PHP_EOL;
        return 0;
    }

    $userFilter = trim((string) ($options['user'] ?? ''));

    $statsDir = rtrim(getenv('PMSS_RUNTIME_DIR') ?: '/var/run/pmss', '/').'/resourceStats';

    if ($userFilter !== '') {
        if (!pmssResourceLogIsValidUser($userFilter)) {
            fwrite(STDERR, "Invalid user specified: {$userFilter}\n");
            return 1;
        }
        $users = [$userFilter];
    } else {
        $listUsersResult = pmssListManagedUsersResult(dirname(__DIR__, 2).'/listUsers.php');
        if ($listUsersResult['exitCode'] !== 0) {
            fwrite(STDERR, "Error: listUsers.php failed; aborting.\n");
            return 1;
        }
        $users = array_values(array_filter($listUsersResult['users'], 'pmssResourceLogIsValidUser'));
        if (empty($users)) {
            die("No users in this system!\n");
        }
        if (is_file($statsDir.'/www-data')) {
            $users[] = 'www-data';
        }
        sort($users, SORT_NATURAL | SORT_FLAG_CASE);
    }

    ['rows' => $rows, 'missing' => $missingStats, 'totals' => $totals] = pmssResourceBuildReport($statsDir, $users);

    if (isset($options['json'])) {
        $buildPayload = static function (array $source): array {
            $payload = [
                'memory' => ['current' => $source['memory_current'], 'avg_month' => $source['memory_avg_month']],
                'tasks' => ['current' => $source['tasks_current']],
            ];

            foreach (pmssResourceMetricNames() as $metric) {
                $payload[$metric] = $source[$metric];
            }

            return $payload;
        };
        echo json_encode([
            'users' => array_map($buildPayload, $rows),
            'totals' => $buildPayload($totals),
            'missing' => $missingStats,
        ])."\n";
        return 0;
    }

    $formatBytes = static function (float $bytes): string {
        foreach ([1099511627776.0 => 'TiB', 1073741824.0 => 'GiB', 1048576.0 => 'MiB'] as $divisor => $unit) {
            if ($bytes >= $divisor) {
                return number_format($bytes / $divisor, 2).' '.$unit;
            }
        }

        return number_format($bytes / 1024, 2).' KiB';
    };
    $rowFormat = "%-14s %-12s %-12s %-11s %-14s %-9s %-6s %-8s\n";
    $printUsageRow = static function (string $label, array $data) use ($formatBytes, $rowFormat): void {
        $hourOps = (float) (($data['io_read_ops']['hour'] ?? 0) + ($data['io_write_ops']['hour'] ?? 0));
        $ramHours = (float) $data['ram_hours']['month'];
        printf(
            $rowFormat,
            $label,
            $formatBytes((float) $data['io_read']['month']),
            $formatBytes((float) $data['io_write']['month']),
            number_format((float) $data['cpu']['month'] / 1000000000 / 3600, 1).' hrs',
            number_format($ramHours, $ramHours >= 100 ? 0 : ($ramHours >= 10 ? 1 : 2)).' GB-hrs',
            $formatBytes((float) $data['memory_current']),
            (string) round($data['tasks_current']),
            number_format($hourOps / 3600, 2)
        );
    };

    printf($rowFormat, 'Username', 'IO Read/mo', 'IO Write/mo', 'CPU hrs/mo', 'RAM GB-hrs/mo', 'Mem Now', 'Procs', 'IOPS/s');
    foreach ($rows as $username => $row) {
        $printUsageRow($username, $row);
    }
    printf($rowFormat, '---', '---', '---', '---', '---', '---', '---', '---');
    $printUsageRow('Total', $totals);

    if (!empty($missingStats)) {
        echo "* Missing resource stats for ".count($missingStats)." users (run resourceStats to rebuild).\n";
        if (isset($options['show-missing'])) {
            echo "* Missing: ".implode(' ', $missingStats)."\n";
        }
    }

    return 0;
}
