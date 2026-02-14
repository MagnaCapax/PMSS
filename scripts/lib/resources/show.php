<?php
/**
 * CLI helper: show per-user resource usage.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/report.php';
require_once __DIR__.'/showFormat.php';
require_once __DIR__.'/log.php';
require_once dirname(__DIR__).'/userLifecycle.php';

function pmssShowResourcesMain(array $argv): int
{
    $options = getopt('', ['json', 'show-missing', 'user:', 'help']);
    if (isset($options['help'])) {
        pmssShowResourcesPrintHelp();
        return 0;
    }

    $asJson = isset($options['json']);
    $showMissing = isset($options['show-missing']);
    $userFilter = isset($options['user']) ? trim((string) $options['user']) : null;

    $runtimeDir = rtrim(getenv('PMSS_RUNTIME_DIR') ?: '/var/run/pmss', '/');
    $statsDir = $runtimeDir.'/resourceStats';

    if ($userFilter !== null && $userFilter !== '') {
        if (!pmssResourceLogIsValidUser($userFilter)) {
            fwrite(STDERR, "Invalid user specified: {$userFilter}\n");
            return 1;
        }
        $users = [$userFilter];
    } else {
        $users = pmssResourceLoadUsers($statsDir);
        if (count($users) === 0) {
            die("No users in this system!\n");
        }
        sort($users, SORT_NATURAL | SORT_FLAG_CASE);
    }

    $report = pmssResourceBuildReport($statsDir, $users);
    $rows = $report['rows'];
    $missingStats = $report['missing'];
    $totals = $report['totals'];

    if ($asJson) {
        $payload = pmssResourceBuildJsonPayload($rows, $totals, $missingStats);
        echo json_encode($payload)."\n";
        return 0;
    }

    pmssShowResourcesPrintHeader();
    foreach ($rows as $username => $row) {
        pmssShowResourcesPrintRow($username, $row);
    }
    pmssShowResourcesPrintTotals($totals);

    if (!empty($missingStats)) {
        echo "* Missing resource stats for ".count($missingStats)." users (run resourceStats to rebuild).\n";
        if ($showMissing) {
            echo "* Missing: ".implode(' ', $missingStats)."\n";
        }
    }

    return 0;
}

function pmssShowResourcesPrintHelp(): void
{
    $self = basename($_SERVER['SCRIPT_NAME'] ?? 'showResources.php');
    echo "Usage: {$self} [--json] [--show-missing] [--user=<username>]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --json          Emit JSON instead of human text output.\n";
    echo "  --show-missing  Print missing stats usernames (text mode only).\n";
    echo "  --user          Show only the named user.\n";
    echo "  --help          Show this help.\n";
    echo "\n";
}

function pmssResourceLoadUsers(string $statsDir): array
{
    $lines = [];
    $rc = 0;
    exec(escapeshellarg(dirname(__DIR__, 2).'/listUsers.php'), $lines, $rc);
    if ($rc !== 0) {
        fwrite(STDERR, "Error: listUsers.php failed; aborting.\n");
        exit(1);
    }
    $users = array_filter(array_map('trim', $lines), 'strlen');
    $users = array_values(array_filter($users, 'pmssResourceLogIsValidUser'));
    if (empty($users)) {
        return [];
    }
    if (is_file($statsDir.'/www-data')) {
        $users[] = 'www-data';
    }
    return $users;
}
