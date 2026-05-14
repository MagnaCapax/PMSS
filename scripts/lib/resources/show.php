<?php
/**
 * CLI helper: show per-user resource usage.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/log.php';
require_once dirname(__DIR__).'/resources.php';
require_once dirname(__DIR__).'/userLifecycle.php';

/**
 * Assemble per-user rows, totals, and missing entries.
 *
 * @return array{rows:array,missing:array,totals:array}
 */
function pmssResourceBuildReport(string $statsDir, array $users): array
{
    $missingStats = $rows = [];
    $totals = pmssResourceReportTemplate();

    foreach ($users as $thisUser) {
        if (!pmssResourceUserIsValid((string) $thisUser)) {
            continue;
        }

        $data = pmssReadSerializedArrayFile("{$statsDir}/{$thisUser}");
        if ($data === null || ($row = pmssResourceStoredPayloadReportRow($data)) === null) {
            $missingStats[] = $thisUser;
            continue;
        }

        foreach ($row as $metric => $fields) {
            foreach ($fields as $label => $value) {
                $totals[$metric][$label] += $value;
            }
        }

        $rows[$thisUser] = $row;
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
    $statsDir = pmssRuntimeDir().'/resourceStats';

    if ($userFilter !== '') {
        if (!pmssResourceUserIsValid($userFilter)) {
            fwrite(STDERR, "Invalid user specified: {$userFilter}\n");
            return 1;
        }
        $users = [$userFilter];
    } else {
        $listUsersResult = pmssListManagedUsersResult(dirname(__DIR__, 2).'/listUsers.php');
        if (($users = pmssListManagedUsersFromResult($listUsersResult)) === null) {
            return 1;
        }
        $users = array_values(array_filter($users, 'pmssResourceUserIsValid'));
        if (empty($users)) {
            die("No users in this system!\n");
        }
        if (is_file($statsDir.'/www-data')) { $users[] = 'www-data'; }
        sort($users, SORT_NATURAL | SORT_FLAG_CASE);
    }

    ['rows' => $rows, 'missing' => $missingStats, 'totals' => $totals] = pmssResourceBuildReport($statsDir, $users);

    if (isset($options['json'])) {
        return pmssJsonEmitPayload(['users' => $rows, 'totals' => $totals, 'missing' => $missingStats], 'Failed to encode resource report JSON.');
    }

    $formatBytes = static function (float $bytes): string {
        foreach ([1099511627776.0 => 'TiB', 1073741824.0 => 'GiB', 1048576.0 => 'MiB'] as $divisor => $unit) {
            if ($bytes >= $divisor) {
                return number_format($bytes / $divisor, 2).' '.$unit;
            }
        }

        return number_format($bytes / 1024, 2).' KiB';
    };
    $formatIoOperations = static function (float $operations): string {
        foreach ([1000000000.0 => 'B ops', 1000000.0 => 'M ops', 1000.0 => 'K ops'] as $divisor => $unit) {
            if ($operations >= $divisor) {
                $value = $operations / $divisor;
                $decimals = $value >= 100 ? 0 : ($value >= 10 ? 1 : 2);
                return number_format($value, $decimals).' '.$unit;
            }
        }

        return number_format($operations, 0).' ops';
    };
    $rowFormat = "%-14s %-12s %-12s %-11s %-14s %-9s %-6s %-10s %-8s\n";
    $printUsageRow = static function (string $label, array $data) use ($formatBytes, $formatIoOperations, $rowFormat): void {
        $hourOps = (float) (($data['io_read_ops']['hour'] ?? 0) + ($data['io_write_ops']['hour'] ?? 0));
        $monthOps = (float) (($data['io_read_ops']['month'] ?? 0) + ($data['io_write_ops']['month'] ?? 0));
        $ramHours = (float) $data['ram_hours']['month'];
        printf(
            $rowFormat,
            $label,
            $formatBytes((float) $data['io_read']['month']),
            $formatBytes((float) $data['io_write']['month']),
            number_format((float) $data['cpu']['month'] / 1000000000 / 3600, 1).' hrs',
            number_format($ramHours, $ramHours >= 100 ? 0 : ($ramHours >= 10 ? 1 : 2)).' GB-hrs',
            $formatBytes((float) $data['memory']['current']),
            (string) round($data['tasks']['current']),
            $formatIoOperations($monthOps),
            number_format($hourOps / 3600, 2)
        );
    };

    printf($rowFormat, 'Username', 'IO Read/mo', 'IO Write/mo', 'CPU hrs/mo', 'RAM GB-hrs/mo', 'Mem Now', 'Procs', 'IO Ops/mo', 'IOPS/s');
    foreach ($rows as $username => $row) {
        $printUsageRow($username, $row);
    }
    printf($rowFormat, '---', '---', '---', '---', '---', '---', '---', '---', '---');
    $printUsageRow('Total', $totals);

    if (!empty($missingStats)) {
        echo "* Missing resource stats for ".count($missingStats)." users (run resourceStats to rebuild).\n";
        if (isset($options['show-missing'])) { echo "* Missing: ".implode(' ', $missingStats)."\n"; }
    }

    return 0;
}
