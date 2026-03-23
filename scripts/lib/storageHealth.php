<?php
/**
 * Storage health facade (SMART/NVMe + mdadm).
 *
 * Keep this include stable: scripts should require this file rather than
 * reaching into submodules. Snapshot entry points live here so callers do not
 * need to know which helper file owns the SMART, NVMe, or RAID path.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/storageHealth/common.php';
require_once __DIR__.'/storageHealth/smart.php';
/**
 * @param array<string, mixed> $disk
 * @param array<string, mixed> $last
 * @return array<string, mixed>|null
 */
function pmssStorageHealthSnapshotNvme(array $disk, array $last, string $timestamp): ?array
{
    $dev = (string) $disk['path'];
    if (strpos($dev, 'nvme') === false || trim((string) shell_exec('command -v nvme 2>/dev/null')) === '') {
        return null;
    }
    $res = pmssStorageHealthExecCapture('nvme smart-log '.escapeshellarg($dev).' 2>/dev/null', 20);
    $out = $res['stdout'];
    if ($out === '') {
        return null;
    }
    $metrics = [
        'critical_warnings' => null,
        'temperature' => null,
        'media_errors' => null,
        'num_err_log_entries' => null,
        'percentage_used' => null,
    ];
    foreach ([
        '/critical_warning\\s*:\\s*(\\d+)/i' => 'critical_warnings',
        '/media_errors\\s*:\\s*(\\d+)/i' => 'media_errors',
        '/num_err_log_entries\\s*:\\s*(\\d+)/i' => 'num_err_log_entries',
        '/percentage_used\\s*:\\s*(\\d+)/i' => 'percentage_used',
    ] as $pattern => $field) {
        if (preg_match($pattern, $out, $matches)) {
            $metrics[$field] = (int) $matches[1];
        }
    }
    if (preg_match('/temperature\\s*:\\s*([0-9]+)\\s*([KC])?/i', $out, $matches)) {
        $value = (int) $matches[1];
        $unit = strtoupper($matches[2] ?? 'C');
        $metrics['temperature'] = $unit === 'K' ? ($value - 273) : $value;
    }
    $entry = [
        'timestamp' => $timestamp,
        'kind' => 'nvme',
        'device' => $dev,
        'kname' => (string) ($disk['kname'] ?? ''),
        'model' => (string) ($disk['model'] ?? ''),
        'serial' => (string) ($disk['serial'] ?? ''),
        'rota' => (int) ($disk['rota'] ?? 0),
        'size' => (string) ($disk['size'] ?? ''),
        'metrics' => $metrics,
    ];
    $flags = [];
    $severity = 'ok';
    if (($metrics['critical_warnings'] ?? 0) > 0) {
        $severity = 'fail';
        $flags[] = 'nvme_critical_warning';
    }
    if (($metrics['temperature'] ?? 0) >= 70) {
        if ($severity === 'ok') {
            $severity = 'warn';
        }
        $flags[] = 'hot_nvme';
    }
    $percentageUsed = (int) ($metrics['percentage_used'] ?? 0);
    if ($percentageUsed >= 80) {
        if ($severity === 'ok') {
            $severity = 'warn';
        }
        $flags[] = $percentageUsed >= 95 ? 'wearout_critical' : 'wearout_high';
    }
    $previous = $last['nvme::'.$dev]['metrics'] ?? null;
    if (is_array($previous)) {
        foreach (['media_errors' => 'media_errors_increase', 'num_err_log_entries' => 'err_log_increase'] as $metric => $flag) {
            if (!isset($metrics[$metric], $previous[$metric]) || $metrics[$metric] <= $previous[$metric]) {
                continue;
            }
            if ($metric === 'media_errors' && $severity === 'ok') {
                $severity = 'warn';
            }
            $flags[] = $flag;
        }
    }
    $entry['flags'] = $flags;
    $entry['severity'] = $severity;
    $entry['ok'] = $severity === 'ok';
    return $entry;
}
/**
 * Read mdadm array status from `/proc/mdstat`.
 *
 * @return array<int, array<string, mixed>>
 */
function pmssStorageHealthSnapshotRaid(string $timestamp): array
{
    $mdstat = @file_get_contents('/proc/mdstat');
    if ($mdstat === false) {
        return [];
    }
    $entries = [];
    foreach (preg_split('/\r?\n/', $mdstat) as $line) {
        $trimmed = trim($line);
        if (preg_match('/^(md\\d+)\\s*:\\s*(\\w+)\\s+(raid\\d)\\s+(.*)$/', $trimmed, $matches)) {
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
                preg_match('/\\[(\\d+)\\/(\\d+)\\]\\s*\\[([U_]+)\\]/', $entry['detail'], $detailMatches)
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
