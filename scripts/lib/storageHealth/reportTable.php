<?php
/**
 * TTY table rendering for storage health reports.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Apply severity coloring only for interactive terminals. */
function pmssStorageHealthColor(string $severity, string $text): string
{
    if (!pmssStreamIsTty(STDOUT)) {
        return $text;
    }
    $code = ['ok' => '32', 'warn' => '33', 'fail' => '31'][$severity] ?? '0';
    return "\033[".$code."m".$text."\033[0m";
}

/** Format nullable integer SMART/NVMe metrics for fixed-width report columns. */
function pmssStorageHealthReportInt($value, string $suffix = ''): string
{
    return is_int($value) ? (string) $value.$suffix : '-';
}

/** @return array<string, string> */
function pmssStorageHealthReportDiskRow(array $entry): array
{
    $metrics = is_array($entry['metrics'] ?? null) ? $entry['metrics'] : [];
    $kind = (string) ($entry['kind'] ?? 'smart');

    return [
        'sev' => (string) ($entry['severity'] ?? 'warn'), 'dev' => (string) ($entry['kname'] ?? ($entry['device'] ?? '')),
        'size' => (string) ($entry['size'] ?? ''), 'model' => (string) ($entry['model'] ?? ''),
        'flags' => is_array($entry['flags'] ?? null) ? implode(',', $entry['flags']) : '',
        'health' => $kind === 'nvme' ? 'NVME' : (string) ($metrics['health'] ?? 'UNKNOWN'),
        'temp' => pmssStorageHealthReportInt($kind === 'nvme' ? ($metrics['temperature'] ?? null) : ($metrics['temp_c'] ?? null), 'C'), 'realloc' => $kind === 'nvme' ? '-' : pmssStorageHealthReportInt($metrics['reallocated'] ?? null),
        'pend' => pmssStorageHealthReportInt($kind === 'nvme' ? ($metrics['media_errors'] ?? null) : ($metrics['pending'] ?? null)), 'link' => pmssStorageHealthReportInt($kind === 'nvme' ? ($metrics['percentage_used'] ?? null) : ($metrics['link_errors'] ?? ($metrics['udma_crc'] ?? null))),
    ];
}

/** @param array<int, array<string, mixed>> $disks @param array<int, array<string, mixed>> $raid */
function pmssStorageHealthPrintTable(array $disks, array $raid, string $timestamp, string $jsonPath): void
{
    $markLabelMap = ['ok' => 'OK', 'warn' => '!!', 'fail' => 'XX'];
    $header = "Storage health (latest snapshot {$timestamp})";
    echo $header.PHP_EOL;
    echo str_repeat('=', strlen($header)).PHP_EOL.PHP_EOL;

    $counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];
    foreach (array_merge($disks, $raid) as $entry) {
        $severity = (string) ($entry['severity'] ?? 'warn');
        $counts[isset($counts[$severity]) ? $severity : 'warn']++;
    }
    echo sprintf("Summary: %s ok, %s warn, %s fail\n\n", (string) $counts['ok'], (string) $counts['warn'], (string) $counts['fail']);

    if (!empty($disks)) {
        echo "Disks\n-----\n";
        $rows = array_map('pmssStorageHealthReportDiskRow', $disks);
        usort($rows, static function (array $a, array $b): int {
            $rank = ['fail' => 0, 'warn' => 1, 'ok' => 2];
            $ra = $rank[$a['sev']] ?? 1;
            $rb = $rank[$b['sev']] ?? 1;
            return $ra !== $rb ? $ra - $rb : strcmp($a['dev'], $b['dev']);
        });
        $modelWidth = 12;
        foreach ($rows as $r) {
            $modelWidth = max($modelWidth, min(34, strlen($r['model'])));
        }
        $fmtHeader = sprintf("%-4s %-4s %-5s %-5s %-".$modelWidth."s %-10s %-5s %-7s %-6s %-6s %s\n", 'MARK', 'SEV', 'DEV', 'SIZE', 'MODEL', 'HEALTH', 'TEMP', 'REALLOC', 'PEND', 'LINK', 'FLAGS');
        echo $fmtHeader;
        echo str_repeat('-', max(20, strlen(rtrim($fmtHeader)))).PHP_EOL;
        foreach ($rows as $r) {
            printf("%-4s %-4s %-5s %-5s %-".$modelWidth."s %-10s %-5s %-7s %-6s %-6s %s\n", pmssStorageHealthColor($r['sev'], $markLabelMap[$r['sev']] ?? '?'), strtoupper($r['sev']), $r['dev'], $r['size'], substr($r['model'], 0, $modelWidth), substr($r['health'], 0, 10), $r['temp'], $r['realloc'], $r['pend'], $r['link'], $r['flags']);
        }
        echo PHP_EOL;
    }

    if (!empty($raid)) {
        echo "MD RAID\n-------\n";
        printf("%-4s %-4s %-6s %-6s %-10s %s\n", 'MARK', 'SEV', 'ARRAY', 'LEVEL', 'STATE', 'DETAIL');
        echo str_repeat('-', 72).PHP_EOL;
        foreach ($raid as $entry) {
            $sev = (string) ($entry['severity'] ?? 'warn');
            printf("%-4s %-4s %-6s %-6s %-10s %s\n", pmssStorageHealthColor($sev, $markLabelMap[$sev] ?? '?'), strtoupper($sev), (string) ($entry['array'] ?? ''), (string) ($entry['level'] ?? ''), (string) ($entry['state'] ?? ''), (string) ($entry['detail'] ?? ''));
        }
        echo PHP_EOL;
    }

    echo "JSONL: ".$jsonPath.PHP_EOL;
}
