<?php
/** Pure mdstat parsing shared by storage snapshots and scheduled RAID checks. @license GPL-3.0-only */

require_once __DIR__.'/common.php';

/** Return unknown when mdstat has no member summary; counts and missing members both matter. */
function pmssStorageHealthRaidDetailDegradedState(string $detail): ?bool
{
    return preg_match('/\[(\d+)\/(\d+)\]\s*\[([U_]+)\]/', $detail, $matches) === 1
        ? strpos($matches[3], '_') !== false || (int) $matches[1] !== (int) $matches[2] : null;
}

/**
 * Parse mdadm array status text.
 *
 * @return array<int, array<string, mixed>>
 */
function pmssStorageHealthRaidEntriesParse(string $mdstat, string $timestamp): array
{
    $entries = [];
    foreach (pmssConfigTextLines($mdstat, []) as $trimmed) {
        if (preg_match('/^(md\\d+)\\s*:\\s*(\\w+)\\s+(raid\\d)\\s+(.*)$/', $trimmed, $matches)) {
            $entries[] = [
                'timestamp' => $timestamp,
                'kind' => 'raid',
                'array' => $matches[1],
                'level' => $matches[3],
                'state' => $matches[2],
                'detail' => $matches[4],
                'ok' => true,
                'severity' => 'ok',
                'flags' => pmssStorageHealthRaidDetailDegradedState($matches[4]) === true ? ['degraded'] : [],
            ];
            $last = count($entries) - 1;
        } elseif (!empty($entries) && preg_match('/\b(check|resync|recovery|reshape)\b/', $trimmed, $operation) === 1) {
            $entries[$last]['flags'][] = 'rebuild_in_progress';
            $entries[$last]['operation'] = $operation[1];
            $entries[$last]['resync'] = $trimmed;
        }
    }
    // Keep RAID's field order and repeated activity flags in the persisted payload.
    foreach ($entries as &$entry) {
        $entry['severity'] = pmssStorageHealthFlagsSeverity($entry['flags']);
        $entry['ok'] = $entry['severity'] === 'ok';
    }
    return $entries;
}

