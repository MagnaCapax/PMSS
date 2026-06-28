<?php
/**
 * CLI helper: show per-user resource usage.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once dirname(__DIR__).'/cli/optionParser.php';
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

        if (($data = pmssReadSerializedArrayFile("{$statsDir}/{$thisUser}")) === null || ($row = pmssResourceStoredPayloadReportRow($data)) === null) {
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
    $self = basename($_SERVER['SCRIPT_NAME'] ?? 'showResources.php');
    $usage = pmssCliHelpUsageOptions($self.' [--json] [--show-missing] [--user=<username>]', [
        ['--json', 'Emit JSON instead of human text output.'],
        ['--show-missing', 'Print missing stats usernames (text mode only).'],
        ['--user', 'Show only the named user.'],
    ]);
    if (($parsed = pmssParseCliTokensOrHelp($argv, $usage, ['user'], null)) === null) return 0;
    $userFilter = trim((string) pmssCliOptionString($parsed, 'user', null, ''));
    $statsDir = pmssRuntimeDir().'/resourceStats';

    if ($userFilter !== '') {
        if (!pmssResourceUserIsValid($userFilter)) {
            return pmssCliReturnWithStderr("Invalid user specified: {$userFilter}\n");
        }
        $users = [$userFilter];
    } else {
        if (($users = pmssListManagedUsersFromResult(pmssListManagedUsersResult(dirname(__DIR__, 2).'/listUsers.php'))) === null) {
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

    if (pmssCliOptionPresent($parsed, 'json')) {
        return pmssJsonEmitPayload(['users' => $rows, 'totals' => $totals, 'missing' => $missingStats], 'Failed to encode resource report JSON.');
    }

    pmssShowResourcesPrintTextReport($rows, $totals, $missingStats, pmssCliOptionPresent($parsed, 'show-missing'));

    return 0;
}

function pmssShowResourcesFormatIoOperations(float $operations): string
{
    foreach ([1000000000.0 => 'B ops', 1000000.0 => 'M ops', 1000.0 => 'K ops'] as $divisor => $unit) {
        if ($operations >= $divisor) {
            $value = $operations / $divisor;
            return number_format($value, $value >= 100 ? 0 : ($value >= 10 ? 1 : 2)).' '.$unit;
        }
    }
    return number_format($operations, 0).' ops';
}

function pmssShowResourcesPrintUsageRow(string $label, array $data, string $rowFormat): void
{
    $hourOps = (float) (($data['io_read_ops']['hour'] ?? 0) + ($data['io_write_ops']['hour'] ?? 0));
    $monthOps = (float) (($data['io_read_ops']['month'] ?? 0) + ($data['io_write_ops']['month'] ?? 0));
    $ramHours = (float) $data['ram_hours']['month'];
    printf(
        $rowFormat,
        $label,
        pmssFormatBytes((float) $data['io_read']['month'], 2, 1),
        pmssFormatBytes((float) $data['io_write']['month'], 2, 1),
        number_format((float) $data['cpu']['month'] / 1000000000 / 3600, 1).' hrs',
        number_format($ramHours, $ramHours >= 100 ? 0 : ($ramHours >= 10 ? 1 : 2)).' GB-hrs',
        pmssFormatBytes((float) $data['memory']['current'], 2, 1),
        (string) round($data['tasks']['current']),
        pmssShowResourcesFormatIoOperations($monthOps),
        number_format($hourOps / 3600, 2)
    );
}

function pmssShowResourcesPrintTextReport(array $rows, array $totals, array $missingStats, bool $showMissing): void
{
    $rowFormat = "%-14s %-12s %-12s %-11s %-14s %-9s %-6s %-10s %-8s\n";
    printf($rowFormat, 'Username', 'IO Read/mo', 'IO Write/mo', 'CPU hrs/mo', 'RAM GB-hrs/mo', 'Mem Now', 'Procs', 'IO Ops/mo', 'IOPS/s');
    foreach ($rows as $username => $row) { pmssShowResourcesPrintUsageRow($username, $row, $rowFormat); }
    printf($rowFormat, '---', '---', '---', '---', '---', '---', '---', '---', '---');
    pmssShowResourcesPrintUsageRow('Total', $totals, $rowFormat);
    if (!empty($missingStats)) {
        echo "* Missing resource stats for ".count($missingStats)." users (run resourceStats to rebuild).\n";
        if ($showMissing) { echo "* Missing: ".implode(' ', $missingStats)."\n"; }
    }
}
