<?php
/**
 * Shared helpers for storage health snapshot/reporting.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Read the latest entry per (kind, device/array) key from a JSONL file.
 *
 * @return array<string, array<string, mixed>>
 */
function pmssStorageHealthReadLastEntries(string $path): array
{
    $fh = is_file($path) ? fopen($path, 'r') : false;
    if ($fh === false) {
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

function pmssStorageHealthSeverityMax(string $a, string $b): string
{
    $rank = ['ok' => 0, 'warn' => 1, 'fail' => 2];
    return (($rank[$b] ?? 1) > ($rank[$a] ?? 1)) ? $b : $a;
}

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
        $isRebuild = in_array('rebuild_in_progress', $flags, true);
        $isDegraded = in_array('degraded', $flags, true) || (string) ($entry['severity'] ?? 'ok') !== 'ok';
        if (!$isRebuild && !$isDegraded) {
            continue;
        }

        $reason = $isRebuild ? "RAID {$arrayName} resync in progress" : "RAID {$arrayName} degraded";
        return ['status' => 'performance_limited', 'reason' => $reason, 'array' => $arrayName];
    }
    return null;
}
