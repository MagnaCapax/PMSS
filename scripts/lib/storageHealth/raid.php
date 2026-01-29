<?php
/**
 * Linux md RAID parsing helpers (/proc/mdstat).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

if (!function_exists('pmssStorageHealthSnapshotRaid')) {
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
            if (preg_match('/^(md\\d+)\\s*:\\s*(\\w+)\\s+(raid\\d)\\s+(.*)$/', trim($line), $m)) {
                $array = $m[1];
                $state = $m[2];
                $level = $m[3];
                $detail = $m[4];
                $entry = [
                    'timestamp' => $timestamp,
                    'kind' => 'raid',
                    'array' => $array,
                    'level' => $level,
                    'state' => $state,
                    'detail' => $detail,
                    'ok' => true,
                    'severity' => 'ok',
                    'flags' => [],
                ];
                if (preg_match('/\\[(\\d+)\\/(\\d+)\\]\\s*\\[([U_]+)\\]/', $detail, $mm)) {
                    $n = (int) $mm[1];
                    $memb = (int) $mm[2];
                    $map = $mm[3];
                    if (strpos($map, '_') !== false || $n !== $memb) {
                        $entry['severity'] = 'fail';
                        $entry['ok'] = false;
                        $entry['flags'][] = 'degraded';
                    }
                }
                $entries[] = $entry;
            } elseif (!empty($entries) && (strpos($line, 'resync') !== false || strpos($line, 'recovery') !== false || strpos($line, 'reshape') !== false)) {
                $lastIdx = count($entries) - 1;
                $entries[$lastIdx]['severity'] = pmssStorageHealthSeverityMax((string) $entries[$lastIdx]['severity'], 'warn');
                $entries[$lastIdx]['ok'] = ($entries[$lastIdx]['severity'] === 'ok');
                $entries[$lastIdx]['flags'][] = 'rebuild_in_progress';
                $entries[$lastIdx]['resync'] = trim($line);
            }
        }
        return $entries;
    }
}

