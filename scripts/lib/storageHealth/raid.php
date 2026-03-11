<?php
/**
 * Linux md RAID parsing helpers (/proc/mdstat).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * @return array<int, array<string, mixed>>
 */
function pmssStorageHealthSnapshotRaid(string $timestamp): array
{
    $entries = [];
    $md = @file_get_contents('/proc/mdstat');
    if ($md === false) {
        return $entries;
    }
    foreach (preg_split('/\r?\n/', $md) as $line) {
        $trimmed = trim($line);
        if (preg_match('/^(md\\d+)\\s*:\\s*(\\w+)\\s+(raid\\d)\\s+(.*)$/', $trimmed, $m)) {
            $entry = [
                'timestamp' => $timestamp,
                'kind' => 'raid',
                'array' => $m[1],
                'level' => $m[3],
                'state' => $m[2],
                'detail' => $m[4],
                'ok' => true,
                'severity' => 'ok',
                'flags' => [],
            ];

            if (preg_match('/\\[(\\d+)\\/(\\d+)\\]\\s*\\[([U_]+)\\]/', $entry['detail'], $mm)
                && (strpos($mm[3], '_') !== false || (int) $mm[1] !== (int) $mm[2])) {
                $entry['severity'] = 'fail';
                $entry['ok'] = false;
                $entry['flags'][] = 'degraded';
            }
            $entries[] = $entry;
            continue;
        }

        if (empty($entries) || preg_match('/\b(check|resync|recovery|reshape)\b/', $line, $operationMatches) !== 1) {
            continue;
        }

        $lastIdx = count($entries) - 1;
        $entries[$lastIdx]['severity'] = pmssStorageHealthSeverityMax((string) $entries[$lastIdx]['severity'], 'warn');
        $entries[$lastIdx]['ok'] = false;
        $entries[$lastIdx]['flags'][] = 'rebuild_in_progress';
        $entries[$lastIdx]['operation'] = $operationMatches[1];
        $entries[$lastIdx]['resync'] = $trimmed;
    }
    return $entries;
}
