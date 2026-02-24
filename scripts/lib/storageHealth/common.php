<?php
/**
 * Shared helpers for storage health snapshot/reporting.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime.php';

if (!function_exists('pmssStorageHealthReadLastEntries')) {
    /**
     * Read the latest entry per (kind, device/array) key from a JSONL file.
     *
     * @return array<string, array<string, mixed>>
     */
    function pmssStorageHealthReadLastEntries(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $fh = fopen($path, 'r');
        if (!$fh) {
            return [];
        }
        $last = [];
        while (($line = fgets($fh)) !== false) {
            $j = json_decode($line, true);
            if (!is_array($j)) {
                continue;
            }
            $kind = (string) ($j['kind'] ?? '');
            $id = (string) ($j['device'] ?? ($j['array'] ?? 'global'));
            $key = $kind.'::'.$id;
            $last[$key] = $j;
        }
        fclose($fh);
        return $last;
    }
}

if (!function_exists('pmssStorageHealthSeverityMax')) {
    function pmssStorageHealthSeverityMax(string $a, string $b): string
    {
        $rank = ['ok' => 0, 'warn' => 1, 'fail' => 2];
        $ra = $rank[$a] ?? 1;
        $rb = $rank[$b] ?? 1;
        return ($rb > $ra) ? $b : $a;
    }
}

if (!function_exists('pmssStorageHealthPerformanceStatus')) {
    /**
     * Detect performance-limiting conditions (e.g., RAID resync/rebuild).
     *
     * @param array<int, array<string,mixed>> $raidEntries
     * @return array<string,string>|null ['status','reason','array']
     */
    function pmssStorageHealthPerformanceStatus(array $raidEntries): ?array
    {
        foreach ($raidEntries as $entry) {
            $flags = (array) ($entry['flags'] ?? []);
            $arrayName = (string) ($entry['array'] ?? 'md');
            if (in_array('rebuild_in_progress', $flags, true)) {
                return [
                    'status' => 'performance_limited',
                    'reason' => "RAID {$arrayName} resync in progress",
                    'array' => $arrayName,
                ];
            }
            if (in_array('degraded', $flags, true) || (string) ($entry['severity'] ?? 'ok') !== 'ok') {
                return [
                    'status' => 'performance_limited',
                    'reason' => "RAID {$arrayName} degraded",
                    'array' => $arrayName,
                ];
            }
        }
        return null;
    }
}
