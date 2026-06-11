<?php
/**
 * NVMe storage-health snapshot backend.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/common.php';

/**
 * @param array<string, mixed> $disk
 * @param array<string, mixed> $last
 * @return array<string, mixed>|null
 */
function pmssStorageHealthSnapshotNvme(array $disk, array $last, string $timestamp): ?array
{
    $dev = (string) $disk['path'];
    if (strpos($dev, 'nvme') === false || pmssCommandPath('nvme') === '') {
        return null;
    }

    $out = pmssStorageHealthExecCapture('nvme smart-log '.escapeshellarg($dev).' 2>/dev/null', 20)['stdout'];
    if ($out === '') {
        return null;
    }

    $metrics = array_fill_keys(['critical_warnings', 'temperature', 'media_errors', 'num_err_log_entries', 'percentage_used'], null);
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
        $metrics['temperature'] = (int) $matches[1] - (strtoupper($matches[2] ?? 'C') === 'K' ? 273 : 0);
    }

    $entry = pmssStorageHealthDeviceEntryBuild('nvme', $disk, $timestamp, 0);
    $entry['metrics'] = $metrics;
    $flags = [];
    $severity = 'ok';
    if (($metrics['critical_warnings'] ?? 0) > 0) {
        $severity = 'fail';
        $flags[] = 'nvme_critical_warning';
    }
    if (($metrics['temperature'] ?? 0) >= 70) {
        $severity = pmssStorageHealthWarnSeverity($severity);
        $flags[] = 'hot_nvme';
    }
    if (($percentageUsed = (int) ($metrics['percentage_used'] ?? 0)) >= 80) {
        $severity = pmssStorageHealthWarnSeverity($severity);
        $flags[] = $percentageUsed >= 95 ? 'wearout_critical' : 'wearout_high';
    }

    $previous = $last['nvme::'.$dev]['metrics'] ?? null;
    if (is_array($previous)) {
        $severity = pmssStorageHealthAppendMetricIncreaseFlags($metrics, $previous, ['media_errors' => 'media_errors_increase', 'num_err_log_entries' => 'err_log_increase'], ['media_errors'], $flags, $severity);
    }
    return pmssStorageHealthEntryFinalize($entry, $flags, $severity);
}
