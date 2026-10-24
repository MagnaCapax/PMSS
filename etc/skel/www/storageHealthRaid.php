<?php
/** Customer-readable mount and RAID observations; loaded by storageHealthNotice.php. */

if (!function_exists('pmssStorageHealthHomeArrayResolve')) {
    /** Resolve which md array backs /home via /proc/mounts. Returns 'mdN' or null. */
    function pmssStorageHealthHomeArrayResolve(?string $mountsPath = null): ?string
    {
        $mountsPath = ($mountsPath !== null && $mountsPath !== '') ? $mountsPath : '/proc/mounts';
        if (!is_array($mounts = @file($mountsPath, FILE_IGNORE_NEW_LINES))) {
            return null;
        }
        foreach ($mounts as $line) {
            $fields = preg_split('/\s+/', trim($line));
            if (!is_array($fields) || count($fields) < 2 || str_replace('\\040', ' ', (string) $fields[1]) !== '/home') {
                continue;
            }
            $resolvedPath = @realpath($mountSource = str_replace('\\040', ' ', (string) $fields[0])) ?: $mountSource;
            return preg_match('#/(md\d+)$#', $resolvedPath, $matches) === 1 ? $matches[1] : null;
        }
        return null;
    }
}

if (!function_exists('pmssStorageHealthSnapshotRaid')) {
    /** Read mdadm array status from /proc/mdstat (world-readable). */
    function pmssStorageHealthSnapshotRaid(string $timestamp): array
    {
        $mdstat = pmssCustomerFileRead('/proc/mdstat', true);
        if (!is_string($mdstat)) {
            return [];
        }
        $entries = [];
        foreach (preg_split('/\r?\n/', $mdstat) as $line) {
            $trimmed = trim($line);
            if (preg_match('/^(md\d+)\s*:\s*(\w+)\s+(raid\d+)\s+(.*)$/', $trimmed, $matches)) {
                $entry = [
                    'timestamp' => $timestamp,
                    'kind' => 'raid',
                    'array' => $matches[1],
                    'level' => $matches[3],
                    'state' => $matches[2],
                    'detail' => $matches[4],
                    'ok' => true,
                    'severity' => 'ok',
                    'flags' => [],
                ];
                if (
                    preg_match('/\[(\d+)\/(\d+)\]\s*\[([U_]+)\]/', $entry['detail'], $detailMatches)
                    && (strpos($detailMatches[3], '_') !== false || (int) $detailMatches[1] !== (int) $detailMatches[2])
                ) {
                    $entry['severity'] = 'fail';
                    $entry['ok'] = false;
                    $entry['flags'][] = 'degraded';
                }
                $entries[] = $entry;
                continue;
            }
            if (!empty($entries) && preg_match('/\b(check|resync|recovery|reshape)\b/', $line, $operationMatches) === 1) {
                $lastIndex = count($entries) - 1;
                if ((string) $entries[$lastIndex]['severity'] === 'ok') {
                    $entries[$lastIndex]['severity'] = 'warn';
                }
                $entries[$lastIndex]['ok'] = false;
                $entries[$lastIndex]['flags'][] = 'rebuild_in_progress';
                $entries[$lastIndex]['operation'] = $operationMatches[1];
                $entries[$lastIndex]['resync'] = $trimmed;
            }
        }
        return $entries;
    }
}

if (!function_exists('pmssStorageHealthHomeRaidActivity')) {
    /**
     * Read current activity for the md array backing /home.
     * @return array<string,mixed>|null
     */
    function pmssStorageHealthHomeRaidActivity(?string $mountsPath = null, ?array $raidEntries = null): ?array
    {
        $homeArray = pmssStorageHealthHomeArrayResolve($mountsPath);
        if ($homeArray === null) {
            return null;
        }
        $raidEntries = $raidEntries ?? pmssStorageHealthSnapshotRaid(date('c'));
        $degradedNotice = null;
        foreach ($raidEntries as $entry) {
            if ((string) ($entry['array'] ?? '') !== $homeArray) {
                continue;
            }
            $activityLine = trim((string) ($entry['resync'] ?? ''));
            if ($activityLine !== '') {
                $summary = pmssStorageHealthRaidActivitySummaryParse($activityLine);
                if ($summary['operation'] !== '') {
                    return $summary + ['array' => $homeArray];
                }
            }
            if (in_array('degraded', (array) ($entry['flags'] ?? []), true)) {
                $degradedNotice = [
                    'array' => $homeArray,
                    'flags' => ['degraded'],
                ];
            }
        }
        return $degradedNotice;
    }
}

