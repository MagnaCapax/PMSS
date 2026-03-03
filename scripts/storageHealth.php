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

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__.'/lib/storageHealth.php';

function pmssStorageHealthUsage(): void
{
    echo "\nStorage health report\n";
    echo "Usage: storageHealth.php [--json <path>] [--raw] [--only-problems] [--device <kname|/dev/...>] [--user-notice[=<path>]]\n\n";
    echo "  --json <path>   JSON Lines input (default /var/log/pmss/storage-health.jsonl)\n";
    echo "  --raw           Print the latest JSON entries (per device) and exit\n";
    echo "  --only-problems Show only warn/fail entries\n";
    echo "  --device <id>   Filter to one device (kname like sda, or path like /dev/sda)\n";
    echo "  --user-notice[=<path>]  Write/clear a user-facing performance notice when perf is limited\n";
    echo "  --help          Show this help\n\n";
}

function pmssStorageHealthColor(string $severity, string $text): string
{
    if (!function_exists('posix_isatty') || !posix_isatty(STDOUT)) {
        return $text;
    }
    $code = ['ok' => '32', 'warn' => '33', 'fail' => '31'][$severity] ?? '0';
    return "\033[".$code."m".$text."\033[0m";
}

function pmssStorageHealthFmtInt($value): string
{
    return is_int($value) ? (string) $value : '-';
}

function pmssStorageHealthFmtTemp($value): string
{
    return is_int($value) ? (string) $value.'C' : '-';
}

function pmssStorageHealthMark(string $severity): string
{
    $mark = ['ok' => 'OK', 'warn' => '!!', 'fail' => 'XX'][$severity] ?? '?';
    return pmssStorageHealthColor($severity, $mark);
}

function pmssStorageHealthPrintSummary(array $entries): void
{
    $counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];
    foreach ($entries as $e) {
        $sev = (string) ($e['severity'] ?? 'warn');
        if (!isset($counts[$sev])) {
            $sev = 'warn';
        }
        $counts[$sev]++;
    }
    echo sprintf(
        "Summary: %s ok, %s warn, %s fail\n\n",
        (string) $counts['ok'],
        (string) $counts['warn'],
        (string) $counts['fail']
    );
}

function pmssStorageHealthPrintTable(array $smart, array $nvme, array $raid, string $timestamp, string $jsonPath): void
{
    $header = "Storage health (latest snapshot {$timestamp})";
    echo $header.PHP_EOL;
    echo str_repeat('=', strlen($header)).PHP_EOL.PHP_EOL;

    pmssStorageHealthPrintSummary(array_merge($smart, $nvme, $raid));

    if (!empty($smart) || !empty($nvme)) {
        echo "Disks\n";
        echo "-----\n";

        $rows = [];
        foreach ($smart as $entry) {
            $m = is_array($entry['metrics'] ?? null) ? $entry['metrics'] : [];
            $device = (string) ($entry['kname'] ?? ($entry['device'] ?? ''));
            $health = (string) ($m['health'] ?? 'UNKNOWN');
            $flags = is_array($entry['flags'] ?? null) ? implode(',', $entry['flags']) : '';
            $rows[] = [
                'sev' => (string) ($entry['severity'] ?? 'warn'),
                'dev' => $device,
                'size' => (string) ($entry['size'] ?? ''),
                'model' => (string) ($entry['model'] ?? ''),
                'health' => $health,
                'temp' => pmssStorageHealthFmtTemp($m['temp_c'] ?? null),
                'realloc' => pmssStorageHealthFmtInt($m['reallocated'] ?? null),
                'pend' => pmssStorageHealthFmtInt($m['pending'] ?? null),
                'link' => pmssStorageHealthFmtInt($m['link_errors'] ?? ($m['udma_crc'] ?? null)),
                'flags' => $flags,
            ];
        }

        foreach ($nvme as $entry) {
            $m = is_array($entry['metrics'] ?? null) ? $entry['metrics'] : [];
            $device = (string) ($entry['kname'] ?? ($entry['device'] ?? ''));
            $flags = is_array($entry['flags'] ?? null) ? implode(',', $entry['flags']) : '';
            $temp = $m['temperature'] ?? null;
            $rows[] = [
                'sev' => (string) ($entry['severity'] ?? 'warn'),
                'dev' => $device,
                'size' => (string) ($entry['size'] ?? ''),
                'model' => (string) ($entry['model'] ?? ''),
                'health' => 'NVME',
                'temp' => pmssStorageHealthFmtTemp(is_int($temp) ? $temp : null),
                'realloc' => '-',
                'pend' => pmssStorageHealthFmtInt($m['media_errors'] ?? null),
                'link' => pmssStorageHealthFmtInt($m['percentage_used'] ?? null),
                'flags' => $flags,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $rank = ['fail' => 0, 'warn' => 1, 'ok' => 2];
            $ra = $rank[$a['sev']] ?? 1;
            $rb = $rank[$b['sev']] ?? 1;
            if ($ra !== $rb) {
                return $ra - $rb;
            }
            return strcmp($a['dev'], $b['dev']);
        });

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
                pmssStorageHealthMark($r['sev']),
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
                pmssStorageHealthMark($sev),
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
    $next = ($i + 1 < $argc) ? $argv[$i + 1] : null;
    $parts = explode('=', $arg, 2);
    $key = $parts[0];
    $val = count($parts) === 2 ? $parts[1] : null;
    switch ($key) {
        case '--json':
            if ($val === null && $next !== null && strpos($next, '--') !== 0) {
                $val = $next;
                $i++;
            }
            if ($val !== null && $val !== '') {
                $jsonPath = $val;
            }
            break;
        case '--raw':
            $raw = true;
            break;
        case '--only-problems':
            $onlyProblems = true;
            break;
        case '--device':
            if ($val === null && $next !== null && strpos($next, '--') !== 0) {
                $val = $next;
                $i++;
            }
            if ($val !== null && $val !== '') {
                $deviceFilter = $val;
            }
            break;
        case '--user-notice':
            $userNoticeRequested = true;
            if ($val === null && $next !== null && strpos($next, '--') !== 0) {
                $val = $next;
                $i++;
            }
            $userNoticePath = $val !== null && $val !== '' ? $val : $defaultNoticePath;
            break;
        case '--help':
        case '-h':
            pmssStorageHealthUsage();
            exit(0);
    }
}

if (!is_file($jsonPath)) {
    fwrite(STDERR, "No snapshot file found at {$jsonPath}\n");
    exit(1);
}

$last = pmssStorageHealthReadLastEntries($jsonPath);
$smart = [];
$nvme = [];
$raid = [];
$latestTs = '';
foreach ($last as $key => $entry) {
    $ts = (string) ($entry['timestamp'] ?? '');
    if ($ts !== '' && ($latestTs === '' || strcmp($ts, $latestTs) > 0)) {
        $latestTs = $ts;
    }
    if (strpos($key, 'smart::') === 0) {
        $smart[] = $entry;
    } elseif (strpos($key, 'nvme::') === 0) {
        $nvme[] = $entry;
    } elseif (strpos($key, 'raid::') === 0) {
        $raid[] = $entry;
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
        if ($kname !== '' && $kname === $wantKname) {
            return true;
        }
        if ($device !== '' && ($device === '/dev/'.$wantKname || $device === $wantKname)) {
            return true;
        }
        return false;
    };
    $smart = array_values(array_filter($smart, $filter));
    $nvme = array_values(array_filter($nvme, $filter));
}

if ($onlyProblems) {
    $filterProblems = static function (array $entry): bool {
        $sev = (string) ($entry['severity'] ?? 'warn');
        return $sev !== 'ok';
    };
    $smart = array_values(array_filter($smart, $filterProblems));
    $nvme = array_values(array_filter($nvme, $filterProblems));
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
    $all = array_merge($smart, $nvme, $raid);
    foreach ($all as $entry) {
        echo json_encode($entry, JSON_UNESCAPED_SLASHES).PHP_EOL;
    }
    exit(0);
}

if (empty($smart) && empty($nvme) && empty($raid)) {
    echo "No storage health entries found in {$jsonPath}\n";
    exit(1);
}

if ($perfStatus !== null) {
    echo pmssStorageHealthColor('warn', 'Performance Limited').": {$perfStatus['reason']}\n\n";
} else {
    echo "Performance status: OK\n\n";
}

pmssStorageHealthPrintTable($smart, $nvme, $raid, $latestTs !== '' ? $latestTs : 'unknown', $jsonPath);
