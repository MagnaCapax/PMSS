<?php
/**
 * TTY table rendering for storage health reports.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

const PMSS_STORAGE_HEALTH_SEVERITY_DISPLAY = ['ok' => ['color' => '32', 'mark' => 'OK', 'rank' => 2], 'warn' => ['color' => '33', 'mark' => '!!', 'rank' => 1], 'fail' => ['color' => '31', 'mark' => 'XX', 'rank' => 0]];

require_once dirname(__DIR__).'/cli/helpText.php';

/** Apply severity coloring only for interactive terminals. */
function pmssStorageHealthColor(string $severity, string $text): string
{
    $code = PMSS_STORAGE_HEALTH_SEVERITY_DISPLAY[$severity]['color'] ?? '0';
    return pmssCliAnsiStyle($text, $code, pmssStreamIsTty(STDOUT));
}

/** Format nullable integer SMART/NVMe metrics for fixed-width report columns. */
function pmssStorageHealthReportInt($value, string $suffix = ''): string
{
    return is_int($value) ? (string) $value.$suffix : '-';
}

/** @param array<int, array<string, mixed>> $disks @param array<int, array<string, mixed>> $raid */
function pmssStorageHealthPrintTable(array $disks, array $raid, string $timestamp, string $jsonPath): void
{
    $header = "Storage health (latest snapshot {$timestamp})";
    echo $header.PHP_EOL;
    echo str_repeat('=', strlen($header)).PHP_EOL.PHP_EOL;

    $counts = ['ok' => 0, 'warn' => 0, 'fail' => 0];
    foreach (array_merge($disks, $raid) as $entry) {
        $severity = (string) ($entry['severity'] ?? 'warn');
        $counts[isset($counts[$severity]) ? $severity : 'warn']++;
    }
    printf("Summary: %s ok, %s warn, %s fail\n\n", (string) $counts['ok'], (string) $counts['warn'], (string) $counts['fail']);

    if (!empty($disks)) {
        echo "Disks\n-----\n";
        // Sort the supplied entries directly; a second display-row schema is unnecessary.
        usort($disks, static function (array $a, array $b): int {
            return ((PMSS_STORAGE_HEALTH_SEVERITY_DISPLAY[(string) ($a['severity'] ?? 'warn')]['rank'] ?? 1)
                - (PMSS_STORAGE_HEALTH_SEVERITY_DISPLAY[(string) ($b['severity'] ?? 'warn')]['rank'] ?? 1))
                ?: strcmp((string) ($a['kname'] ?? ($a['device'] ?? '')), (string) ($b['kname'] ?? ($b['device'] ?? '')));
        });
        $modelWidth = 12;
        foreach ($disks as $entry) {
            $modelWidth = max($modelWidth, min(34, strlen((string) ($entry['model'] ?? ''))));
        }
        $format = "%-4s %-4s %-5s %-5s %-".$modelWidth."s %-10s %-5s %-7s %-6s %-6s %s\n";
        $fmtHeader = sprintf($format, 'MARK', 'SEV', 'DEV', 'SIZE', 'MODEL', 'HEALTH', 'TEMP', 'REALLOC', 'PEND', 'LINK', 'FLAGS');
        echo $fmtHeader;
        echo str_repeat('-', max(20, strlen(rtrim($fmtHeader)))).PHP_EOL;
        foreach ($disks as $entry) {
            $sev = (string) ($entry['severity'] ?? 'warn');
            $metrics = is_array($entry['metrics'] ?? null) ? $entry['metrics'] : [];
            $nvme = (string) ($entry['kind'] ?? 'smart') === 'nvme';
            printf($format, pmssStorageHealthColor($sev, PMSS_STORAGE_HEALTH_SEVERITY_DISPLAY[$sev]['mark'] ?? '?'), strtoupper($sev),
                (string) ($entry['kname'] ?? ($entry['device'] ?? '')), (string) ($entry['size'] ?? ''), substr((string) ($entry['model'] ?? ''), 0, $modelWidth),
                substr($nvme ? 'NVME' : (string) ($metrics['health'] ?? 'UNKNOWN'), 0, 10),
                pmssStorageHealthReportInt($metrics[$nvme ? 'temperature' : 'temp_c'] ?? null, 'C'), $nvme ? '-' : pmssStorageHealthReportInt($metrics['reallocated'] ?? null),
                pmssStorageHealthReportInt($metrics[$nvme ? 'media_errors' : 'pending'] ?? null), pmssStorageHealthReportInt($nvme ? ($metrics['percentage_used'] ?? null) : ($metrics['link_errors'] ?? ($metrics['udma_crc'] ?? null))),
                is_array($entry['flags'] ?? null) ? implode(',', $entry['flags']) : '');
        }
        echo PHP_EOL;
    }

    if (!empty($raid)) {
        echo "MD RAID\n-------\n";
        printf("%-4s %-4s %-6s %-6s %-10s %s\n", 'MARK', 'SEV', 'ARRAY', 'LEVEL', 'STATE', 'DETAIL');
        echo str_repeat('-', 72).PHP_EOL;
        foreach ($raid as $entry) {
            $sev = (string) ($entry['severity'] ?? 'warn');
            printf("%-4s %-4s %-6s %-6s %-10s %s\n", pmssStorageHealthColor($sev, PMSS_STORAGE_HEALTH_SEVERITY_DISPLAY[$sev]['mark'] ?? '?'), strtoupper($sev), (string) ($entry['array'] ?? ''), (string) ($entry['level'] ?? ''), (string) ($entry['state'] ?? ''), (string) ($entry['detail'] ?? ''));
        }
        echo PHP_EOL;
    }

    echo "JSONL: ".$jsonPath.PHP_EOL;
}
