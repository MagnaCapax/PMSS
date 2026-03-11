<?php
/**
 * CLI helper: show per-user resource usage.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/report.php';
require_once __DIR__.'/showFormat.php';
require_once __DIR__.'/userHelpers.php';
require_once dirname(__DIR__).'/userLifecycle.php';

function pmssShowResourcesMain(array $argv): int
{
    $options = getopt('', ['json', 'show-missing', 'user:', 'help']);
    if (isset($options['help'])) {
        $self = basename($_SERVER['SCRIPT_NAME'] ?? 'showResources.php');
        echo "Usage: {$self} [--json] [--show-missing] [--user=<username>]\n\n"
            ."Options:\n"
            ."  --json          Emit JSON instead of human text output.\n"
            ."  --show-missing  Print missing stats usernames (text mode only).\n"
            ."  --user          Show only the named user.\n"
            ."  --help          Show this help.\n\n";
        return 0;
    }

    $asJson = isset($options['json']);
    $showMissing = isset($options['show-missing']);
    $userFilter = isset($options['user']) ? trim((string) $options['user']) : null;

    $statsDir = rtrim(getenv('PMSS_RUNTIME_DIR') ?: '/var/run/pmss', '/').'/resourceStats';

    if ($userFilter !== null && $userFilter !== '') {
        if (!pmssResourceLogIsValidUser($userFilter)) {
            fwrite(STDERR, "Invalid user specified: {$userFilter}\n");
            return 1;
        }
        $users = [$userFilter];
    } else {
        $lines = [];
        $rc = 0;
        exec(escapeshellarg(dirname(__DIR__, 2).'/listUsers.php'), $lines, $rc);
        if ($rc !== 0) {
            fwrite(STDERR, "Error: listUsers.php failed; aborting.\n");
            return 1;
        }
        $users = array_values(array_filter(array_map('trim', $lines), 'pmssResourceLogIsValidUser'));
        if (count($users) === 0) {
            die("No users in this system!\n");
        }
        if (is_file($statsDir.'/www-data')) {
            $users[] = 'www-data';
        }
        sort($users, SORT_NATURAL | SORT_FLAG_CASE);
    }

    ['rows' => $rows, 'missing' => $missingStats, 'totals' => $totals] = pmssResourceBuildReport($statsDir, $users);

    if ($asJson) {
        echo json_encode(pmssResourceBuildJsonPayload($rows, $totals, $missingStats))."\n";
        return 0;
    }

    $rowFormat = "%-14s %-12s %-12s %-11s %-14s %-9s %-6s %-8s\n";
    $printUsageRow = static function (string $label, array $data) use ($rowFormat): void {
        $hourOps = (float) (($data['io_read_ops']['hour'] ?? 0) + ($data['io_write_ops']['hour'] ?? 0));
        printf(
            $rowFormat,
            $label,
            pmssResourceFormatBytes($data['io_read']['month']),
            pmssResourceFormatBytes($data['io_write']['month']),
            pmssResourceFormatCpuHours($data['cpu']['month']),
            pmssResourceFormatRamHours($data['ram_hours']['month']),
            pmssResourceFormatBytes($data['memory_current']),
            (string) round($data['tasks_current']),
            pmssResourceFormatOpsPerSecond($hourOps, 3600)
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
        if ($showMissing) {
            echo "* Missing: ".implode(' ', $missingStats)."\n";
        }
    }

    return 0;
}
