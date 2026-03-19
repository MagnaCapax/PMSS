<?php
/**
 * NVMe parsing + snapshot helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

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

    $metrics = ['critical_warnings' => null, 'temperature' => null, 'media_errors' => null, 'num_err_log_entries' => null, 'percentage_used' => null];
    foreach ([
        '/critical_warning\\s*:\\s*(\\d+)/i' => 'critical_warnings',
        '/media_errors\\s*:\\s*(\\d+)/i' => 'media_errors',
        '/num_err_log_entries\\s*:\\s*(\\d+)/i' => 'num_err_log_entries',
        '/percentage_used\\s*:\\s*(\\d+)/i' => 'percentage_used',
    ] as $pattern => $field) {
        if (preg_match($pattern, $out, $m)) {
            $metrics[$field] = (int) $m[1];
        }
    }
    if (preg_match('/temperature\\s*:\\s*([0-9]+)\\s*([KC])?/i', $out, $m)) {
        $val = (int) $m[1];
        $unit = strtoupper($m[2] ?? 'C');
        $metrics['temperature'] = ($unit === 'K') ? ($val - 273) : $val;
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
    $sev = 'ok';
    if (($metrics['critical_warnings'] ?? 0) > 0) {
        $sev = pmssStorageHealthSeverityMax($sev, 'fail');
        $flags[] = 'nvme_critical_warning';
    }
    if (($metrics['temperature'] ?? 0) >= 70) {
        $sev = pmssStorageHealthSeverityMax($sev, 'warn');
        $flags[] = 'hot_nvme';
    }
    $percentageUsed = (int) ($metrics['percentage_used'] ?? 0);
    if ($percentageUsed >= 80) {
        $sev = pmssStorageHealthSeverityMax($sev, 'warn');
        $flags[] = ($percentageUsed >= 95) ? 'wearout_critical' : 'wearout_high';
    }

    $prev = $last['nvme::'.$dev]['metrics'] ?? null;
    if (is_array($prev)) {
        foreach (['media_errors' => 'media_errors_increase', 'num_err_log_entries' => 'err_log_increase'] as $metric => $flag) {
            if (!isset($metrics[$metric], $prev[$metric]) || $metrics[$metric] <= $prev[$metric]) {
                continue;
            }
            if ($metric === 'media_errors') {
                $sev = pmssStorageHealthSeverityMax($sev, 'warn');
            }
            $flags[] = $flag;
        }
    }

    $entry['flags'] = $flags;
    $entry['severity'] = $sev;
    $entry['ok'] = ($sev === 'ok');

    return $entry;
}
