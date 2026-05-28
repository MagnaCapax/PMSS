<?php
/**
 * CLI orchestration for the operator-facing storage health report.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../cli/optionParser.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';
require_once __DIR__.'/../storageHealth.php';
require_once __DIR__.'/reportTable.php';

/** Build help text close to the CLI parser so option contracts stay visible. */
function pmssStorageHealthReportHelpText(): string
{
    return "\nStorage health report\n".pmssCliHelpUsageOptions('storageHealth.php [--json <path>] [--raw] [--only-problems] [--device <kname|/dev/...>] [--user-notice[=<path>]]', [
        ['--json <path>', 'JSON Lines input (default /var/log/pmss/storage-health.jsonl).'],
        ['--raw', 'Print the latest JSON entries (per device) and exit.'],
        ['--only-problems', 'Show only warn/fail entries.'],
        ['--device <id>', 'Filter to one device (kname like sda, or path like /dev/sda).'],
        ['--user-notice[=<path>]', 'Write/clear a user-facing performance notice when perf is limited.'],
        ['--help', 'Show this help.'],
    ], 28);
}

/** @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>,2:string} */
function pmssStorageHealthReportEntries(string $jsonPath): array
{
    $disks = [];
    $raid = [];
    $latestTs = '';
    foreach (pmssStorageHealthReadLastEntries($jsonPath) as $entry) {
        $ts = (string) ($entry['timestamp'] ?? '');
        $kind = (string) ($entry['kind'] ?? '');
        if ($ts !== '' && ($latestTs === '' || strcmp($ts, $latestTs) > 0)) {
            $latestTs = $ts;
        }
        if ($kind === 'raid') {
            $raid[] = $entry;
        } elseif ($kind === 'smart' || $kind === 'nvme') {
            $disks[] = $entry;
        }
    }
    return [$disks, $raid, $latestTs];
}

/** @param array<int,array<string,mixed>> $entries @return array<int,array<string,mixed>> */
function pmssStorageHealthReportFilter(array $entries, ?string $deviceFilter, bool $onlyProblems): array
{
    $wantKname = is_string($deviceFilter) && $deviceFilter !== '' ? ltrim($deviceFilter) : null;
    if ($wantKname !== null && strpos($wantKname, '/dev/') === 0) {
        $wantKname = substr($wantKname, strlen('/dev/'));
    }
    if ($wantKname === null && !$onlyProblems) {
        return $entries;
    }
    return array_values(array_filter($entries, static function (array $entry) use ($wantKname, $onlyProblems): bool {
        if ($onlyProblems && (string) ($entry['severity'] ?? 'warn') === 'ok') {
            return false;
        }
        if ($wantKname === null) {
            return true;
        }
        $kname = (string) ($entry['kname'] ?? '');
        $device = (string) ($entry['device'] ?? '');
        return ($kname !== '' && $kname === $wantKname)
            || ($device !== '' && ($device === '/dev/'.$wantKname || $device === $wantKname));
    }));
}

/** Keep the customer-readable performance notice synchronized with report state. */
function pmssStorageHealthReportSyncUserNotice(string $noticePath, ?array $perfStatus, string $latestTs): void
{
    $noticePayload = $perfStatus === null ? null : [
        'timestamp' => $latestTs !== '' ? $latestTs : date('c'),
        'status' => $perfStatus['status'],
        'reason' => $perfStatus['reason'],
        'array' => $perfStatus['array'],
    ];
    if (!pmssUserFilePathIsSafe($noticePath)) {
        return;
    }
    if ($noticePayload === null && is_file($noticePath)) {
        @unlink($noticePath);
        return;
    }
    $json = $noticePayload === null ? '' : json_encode($noticePayload, JSON_UNESCAPED_SLASHES);
    $noticeDir = dirname($noticePath);
    if (is_string($json) && $json !== '' && ($noticeDir === '' || pmssEnsureSafeDir($noticeDir, 0755))) {
        pmssAtomicWriteFile($noticePath, $json.PHP_EOL, 0644);
    }
}

function pmssStorageHealthReportMain(array $argv): int
{
    $jsonPath = '/var/log/pmss/storage-health.jsonl';
    $defaultNoticePath = getenv('PMSS_STORAGE_USER_NOTICE') ?: '/etc/seedbox/config/storagePerformanceNotice.json';
    $parsed = pmssParseCliTokens($argv);

    if (pmssCliHelpTextEmitIfRequested($parsed, pmssStorageHealthReportHelpText())) {
        return 0;
    }

    $jsonPath = pmssCliOptionString($parsed, 'json', null, $jsonPath) ?? $jsonPath;
    if (!is_file($jsonPath)) {
        fwrite(STDERR, "No snapshot file found at {$jsonPath}\n");
        return 1;
    }

    [$disks, $raid, $latestTs] = pmssStorageHealthReportEntries($jsonPath);
    $onlyProblems = pmssCliOptionPresent($parsed, 'only-problems');
    $disks = pmssStorageHealthReportFilter($disks, pmssCliOptionString($parsed, 'device', null, null), $onlyProblems);
    $raid = pmssStorageHealthReportFilter($raid, null, $onlyProblems);
    $perfStatus = pmssStorageHealthPerformanceStatus($raid);

    if (pmssCliOptionPresent($parsed, 'user-notice')) {
        $noticePath = pmssCliOptionString($parsed, 'user-notice', null, $defaultNoticePath) ?? $defaultNoticePath;
        pmssStorageHealthReportSyncUserNotice($noticePath, $perfStatus, $latestTs);
    }
    if (pmssCliOptionPresent($parsed, 'raw')) {
        foreach (array_merge($disks, $raid) as $entry) {
            echo json_encode($entry, JSON_UNESCAPED_SLASHES).PHP_EOL;
        }
        return 0;
    }
    if (empty($disks) && empty($raid)) {
        echo "No storage health entries found in {$jsonPath}\n";
        return 1;
    }

    echo $perfStatus !== null
        ? pmssStorageHealthColor('warn', 'Performance Limited').": {$perfStatus['reason']}\n\n"
        : "Performance status: OK\n\n";
    pmssStorageHealthPrintTable($disks, $raid, $latestTs !== '' ? $latestTs : 'unknown', $jsonPath);
    return 0;
}
