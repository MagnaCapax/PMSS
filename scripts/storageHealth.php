#!/usr/bin/env php
<?php
/**
 * storageHealth.php
 *
 * Operator-facing storage health report (TTY table) based on the JSONL
 * snapshots written by `scripts/cron/storageHealthSnapshot.php`.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/lib/runtime.php';

pmssRequireCli();

require_once __DIR__.'/lib/storageHealth.php';

function pmssStorageHealthColor(string $severity, string $text): string
{
    if (!pmssStreamIsTty(STDOUT)) {
        return $text;
    }
    $code = ['ok' => '32', 'warn' => '33', 'fail' => '31'][$severity] ?? '0';
    return "\033[".$code."m".$text."\033[0m";
}

function pmssStorageHealthFormatInt($value, string $suffix = ''): string
{
    return is_int($value) ? (string) $value.$suffix : '-';
}

function pmssStorageHealthDiskRowBuild(array $entry): array
{
    $metrics = is_array($entry['metrics'] ?? null) ? $entry['metrics'] : [];
    $kind = (string) ($entry['kind'] ?? 'smart');
    $row = [
        'sev' => (string) ($entry['severity'] ?? 'warn'),
        'dev' => (string) ($entry['kname'] ?? ($entry['device'] ?? '')),
        'size' => (string) ($entry['size'] ?? ''),
        'model' => (string) ($entry['model'] ?? ''),
        'flags' => is_array($entry['flags'] ?? null) ? implode(',', $entry['flags']) : '',
    ];

    $row['health'] = $kind === 'nvme' ? 'NVME' : (string) ($metrics['health'] ?? 'UNKNOWN');
    $row['temp'] = pmssStorageHealthFormatInt($kind === 'nvme' ? ($metrics['temperature'] ?? null) : ($metrics['temp_c'] ?? null), 'C');
    $row['realloc'] = $kind === 'nvme' ? '-' : pmssStorageHealthFormatInt($metrics['reallocated'] ?? null);
    $row['pend'] = pmssStorageHealthFormatInt($kind === 'nvme' ? ($metrics['media_errors'] ?? null) : ($metrics['pending'] ?? null));
    $row['link'] = pmssStorageHealthFormatInt($kind === 'nvme' ? ($metrics['percentage_used'] ?? null) : ($metrics['link_errors'] ?? ($metrics['udma_crc'] ?? null)));

    return $row;
}

function pmssStorageHealthOptionValue(array $argv, int $argc, int &$index, ?string $value): ?string
{
    if ($value === null && $index + 1 < $argc && strpos($argv[$index + 1], '--') !== 0) {
        $index++;
        $value = $argv[$index];
    }

    return ($value !== null && $value !== '') ? $value : null;
}

function pmssStorageHealthSeverityCounts(array $entries): array
{
    $counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];
    foreach ($entries as $entry) {
        $severity = (string) ($entry['severity'] ?? 'warn');
        $counts[isset($counts[$severity]) ? $severity : 'warn']++;
    }

    return $counts;
}

function pmssStorageHealthDiskRows(array $entries): array
{
    $rows = array_map('pmssStorageHealthDiskRowBuild', $entries);

    usort($rows, static function (array $a, array $b): int {
        $rank = ['fail' => 0, 'warn' => 1, 'ok' => 2];
        $ra = $rank[$a['sev']] ?? 1;
        $rb = $rank[$b['sev']] ?? 1;
        return $ra !== $rb ? $ra - $rb : strcmp($a['dev'], $b['dev']);
    });

    return $rows;
}

function pmssStorageHealthPrintTable(array $disks, array $raid, string $timestamp, string $jsonPath): void
{
    $markLabelMap = ['ok' => 'OK', 'warn' => '!!', 'fail' => 'XX'];

    $header = "Storage health (latest snapshot {$timestamp})";
    echo $header.PHP_EOL;
    echo str_repeat('=', strlen($header)).PHP_EOL.PHP_EOL;

    $counts = pmssStorageHealthSeverityCounts(array_merge($disks, $raid));
    echo sprintf(
        "Summary: %s ok, %s warn, %s fail\n\n",
        (string) $counts['ok'],
        (string) $counts['warn'],
        (string) $counts['fail']
    );

    if (!empty($disks)) {
        echo "Disks\n";
        echo "-----\n";

        $rows = pmssStorageHealthDiskRows($disks);

        $modelWidth = 12;
        foreach ($rows as $r) {
            $modelWidth = max($modelWidth, min(34, strlen($r['model'])));
        }

        $fmtHeader = sprintf(
            "%-4s %-4s %-5s %-5s %-".$modelWidth."s %-10s %-5s %-7s %-6s %-6s %s\n",
            'MARK',
            'SEV',
            'DEV',
            'SIZE',
            'MODEL',
            'HEALTH',
            'TEMP',
            'REALLOC',
            'PEND',
            'LINK',
            'FLAGS'
        );
        echo $fmtHeader;
        echo str_repeat('-', max(20, strlen(rtrim($fmtHeader)))).PHP_EOL;

        foreach ($rows as $r) {
            $sevTxt = strtoupper($r['sev']);
            printf(
                "%-4s %-4s %-5s %-5s %-".$modelWidth."s %-10s %-5s %-7s %-6s %-6s %s\n",
                pmssStorageHealthColor($r['sev'], $markLabelMap[$r['sev']] ?? '?'),
                $sevTxt,
                $r['dev'],
                $r['size'],
                substr($r['model'], 0, $modelWidth),
                substr($r['health'], 0, 10),
                $r['temp'],
                $r['realloc'],
                $r['pend'],
                $r['link'],
                $r['flags']
            );
        }
        echo PHP_EOL;
    }

    if (!empty($raid)) {
        echo "MD RAID\n";
        echo "-------\n";
        printf("%-4s %-4s %-6s %-6s %-10s %s\n", 'MARK', 'SEV', 'ARRAY', 'LEVEL', 'STATE', 'DETAIL');
        echo str_repeat('-', 72).PHP_EOL;
        foreach ($raid as $entry) {
            $sev = (string) ($entry['severity'] ?? 'warn');
            printf(
                "%-4s %-4s %-6s %-6s %-10s %s\n",
                pmssStorageHealthColor($sev, $markLabelMap[$sev] ?? '?'),
                strtoupper($sev),
                (string) ($entry['array'] ?? ''),
                (string) ($entry['level'] ?? ''),
                (string) ($entry['state'] ?? ''),
                (string) ($entry['detail'] ?? '')
            );
        }
        echo PHP_EOL;
    }

    echo "JSONL: ".$jsonPath.PHP_EOL;
}

$jsonPath = '/var/log/pmss/storage-health.jsonl';
$raw = false;
$onlyProblems = false;
$deviceFilter = null;
$userNoticePath = '';
$userNoticeRequested = false;
$defaultNoticePath = getenv('PMSS_STORAGE_USER_NOTICE') ?: '/etc/seedbox/config/storagePerformanceNotice.json';

$argc = count($argv);
for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    [$key, $val] = array_pad(explode('=', $arg, 2), 2, null);
    switch ($key) {
        case '--json':
            $jsonPath = pmssStorageHealthOptionValue($argv, $argc, $i, $val) ?? $jsonPath;
            break;
        case '--raw':
            $raw = true;
            break;
        case '--only-problems':
            $onlyProblems = true;
            break;
        case '--device':
            $deviceFilter = pmssStorageHealthOptionValue($argv, $argc, $i, $val) ?? $deviceFilter;
            break;
        case '--user-notice':
            $userNoticeRequested = true;
            $userNoticePath = pmssStorageHealthOptionValue($argv, $argc, $i, $val) ?? $defaultNoticePath;
            break;
        case '--help':
        case '-h':
            echo "\nStorage health report\n";
            echo "Usage: storageHealth.php [--json <path>] [--raw] [--only-problems] [--device <kname|/dev/...>] [--user-notice[=<path>]]\n\n";
            echo "  --json <path>   JSON Lines input (default /var/log/pmss/storage-health.jsonl)\n";
            echo "  --raw           Print the latest JSON entries (per device) and exit\n";
            echo "  --only-problems Show only warn/fail entries\n";
            echo "  --device <id>   Filter to one device (kname like sda, or path like /dev/sda)\n";
            echo "  --user-notice[=<path>]  Write/clear a user-facing performance notice when perf is limited\n";
            echo "  --help          Show this help\n\n";
            exit(0);
    }
}

if (!is_file($jsonPath)) {
    fwrite(STDERR, "No snapshot file found at {$jsonPath}\n");
    exit(1);
}

$last = pmssStorageHealthReadLastEntries($jsonPath);
$disks = [];
$raid = [];
$latestTs = '';
foreach ($last as $entry) {
    $ts = (string) ($entry['timestamp'] ?? '');
    $kind = (string) ($entry['kind'] ?? '');
    if ($ts !== '' && ($latestTs === '' || strcmp($ts, $latestTs) > 0)) {
        $latestTs = $ts;
    }
    if ($kind === 'raid') {
        $raid[] = $entry;
        continue;
    }
    if ($kind === 'smart' || $kind === 'nvme') {
        $disks[] = $entry;
    }
}

if (is_string($deviceFilter) && $deviceFilter !== '') {
    $wantKname = ltrim($deviceFilter);
    if (strpos($wantKname, '/dev/') === 0) {
        $wantKname = substr($wantKname, strlen('/dev/'));
    }
    $filter = static function (array $entry) use ($wantKname): bool {
        $kname = (string) ($entry['kname'] ?? '');
        $device = (string) ($entry['device'] ?? '');
        return ($kname !== '' && $kname === $wantKname)
            || ($device !== '' && ($device === '/dev/'.$wantKname || $device === $wantKname));
    };
    $disks = array_values(array_filter($disks, $filter));
}

if ($onlyProblems) {
    $filterProblems = static function (array $entry): bool {
        return (string) ($entry['severity'] ?? 'warn') !== 'ok';
    };
    $disks = array_values(array_filter($disks, $filterProblems));
    $raid = array_values(array_filter($raid, $filterProblems));
}

$perfStatus = pmssStorageHealthPerformanceStatus($raid);

if ($userNoticeRequested && $userNoticePath !== '') {
    if ($perfStatus !== null) {
        $userNoticeDir = dirname($userNoticePath);
        if ($userNoticeDir !== '' && !is_dir($userNoticeDir)) {
            @mkdir($userNoticeDir, 0755, true);
        }
        $payload = [
            'timestamp' => $latestTs !== '' ? $latestTs : date('c'),
            'status' => $perfStatus['status'],
            'reason' => $perfStatus['reason'],
            'array' => $perfStatus['array'],
        ];
        @file_put_contents($userNoticePath, json_encode($payload, JSON_UNESCAPED_SLASHES).PHP_EOL);
        @chmod($userNoticePath, 0644);
    } elseif (is_file($userNoticePath)) {
        @unlink($userNoticePath);
    }
}

if ($raw) {
    foreach (array_merge($disks, $raid) as $entry) {
        echo json_encode($entry, JSON_UNESCAPED_SLASHES).PHP_EOL;
    }
    exit(0);
}

if (empty($disks) && empty($raid)) {
    echo "No storage health entries found in {$jsonPath}\n";
    exit(1);
}

if ($perfStatus !== null) {
    echo pmssStorageHealthColor('warn', 'Performance Limited').": {$perfStatus['reason']}\n\n";
} else {
    echo "Performance status: OK\n\n";
}

pmssStorageHealthPrintTable($disks, $raid, $latestTs !== '' ? $latestTs : 'unknown', $jsonPath);
