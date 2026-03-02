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
        if (strpos($dev, 'nvme') === false) {
            return null;
        }
        if (trim((string) shell_exec('command -v nvme 2>/dev/null')) === '') {
            return null;
        }
        $res = pmssStorageHealthExecCapture('nvme smart-log '.escapeshellarg($dev).' 2>/dev/null', 20);
        $out = $res['stdout'];
        if (!$out) {
            return null;
        }
        $metrics = [
            'critical_warnings' => null,
            'temperature' => null,
            'media_errors' => null,
            'num_err_log_entries' => null,
            'percentage_used' => null,
        ];
        if (preg_match('/critical_warning\\s*:\\s*(\\d+)/i', $out, $m)) {
            $metrics['critical_warnings'] = (int) $m[1];
        }
        if (preg_match('/temperature\\s*:\\s*([0-9]+)\\s*([KC])?/i', $out, $m)) {
            $val = (int) $m[1];
            $unit = strtoupper($m[2] ?? 'C');
            $metrics['temperature'] = ($unit === 'K') ? ($val - 273) : $val;
        }
        if (preg_match('/media_errors\\s*:\\s*(\\d+)/i', $out, $m)) {
            $metrics['media_errors'] = (int) $m[1];
        }
        if (preg_match('/num_err_log_entries\\s*:\\s*(\\d+)/i', $out, $m)) {
            $metrics['num_err_log_entries'] = (int) $m[1];
        }
        if (preg_match('/percentage_used\\s*:\\s*(\\d+)/i', $out, $m)) {
            $metrics['percentage_used'] = (int) $m[1];
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
            'ok' => true,
            'severity' => 'ok',
            'flags' => [],
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
        if (($metrics['percentage_used'] ?? 0) >= 95) {
            $sev = pmssStorageHealthSeverityMax($sev, 'warn');
            $flags[] = 'wearout_critical';
        } elseif (($metrics['percentage_used'] ?? 0) >= 80) {
            $sev = pmssStorageHealthSeverityMax($sev, 'warn');
            $flags[] = 'wearout_high';
        }

        $prev = $last['nvme::'.$dev]['metrics'] ?? null;
        if (is_array($prev)) {
            if (isset($metrics['media_errors'], $prev['media_errors']) && $metrics['media_errors'] > $prev['media_errors']) {
                $sev = pmssStorageHealthSeverityMax($sev, 'warn');
                $flags[] = 'media_errors_increase';
            }
            if (isset($metrics['num_err_log_entries'], $prev['num_err_log_entries']) && $metrics['num_err_log_entries'] > $prev['num_err_log_entries']) {
                $flags[] = 'err_log_increase';
            }
        }

        $entry['flags'] = $flags;
        $entry['severity'] = $sev;
        $entry['ok'] = ($sev === 'ok');
    return $entry;
}
