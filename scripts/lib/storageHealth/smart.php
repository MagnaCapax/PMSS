<?php
/**
 * SMART parsing + snapshot helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

if (!function_exists('pmssStorageHealthParseSmartctlOutput')) {
    /**
     * Parse smartctl output into a stable metric set.
     *
     * @param array<string, mixed> $disk
     * @param array<string, mixed>|null $prevMetrics
     * @return array<string, mixed>
     */
    function pmssStorageHealthParseSmartctlOutput(string $out, array $disk, ?array $prevMetrics, string $timestamp): array
    {
        $dev = (string) $disk['path'];
        $entry = [
            'timestamp' => $timestamp,
            'kind' => 'smart',
            'device' => $dev,
            'kname' => (string) ($disk['kname'] ?? ''),
            'model' => (string) ($disk['model'] ?? ''),
            'serial' => (string) ($disk['serial'] ?? ''),
            'rota' => (int) ($disk['rota'] ?? 1),
            'size' => (string) ($disk['size'] ?? ''),
            'ok' => false,
            'severity' => 'warn',
        ];

        if (stripos($out, 'Device is in STANDBY') !== false || stripos($out, 'Device is in SLEEP') !== false) {
            $entry['metrics'] = [
                'health' => 'STANDBY',
                'reallocated' => null,
                'pending' => null,
                'udma_crc' => null,
                'temp_c' => null,
                'power_on_hours' => null,
                'link_errors' => null,
            ];
            $entry['flags'] = ['standby'];
            $entry['severity'] = 'ok';
            $entry['ok'] = true;
            return $entry;
        }

        $metrics = [
            'health' => 'UNKNOWN',
            'reallocated' => null,
            'pending' => null,
            'udma_crc' => null,
            'temp_c' => null,
            'power_on_hours' => null,
            'link_errors' => null,
        ];

        $healthExplicit = false;
        if (preg_match('/SMART overall-health\\s+self-assessment\\s+test\\s+result:\\s*(.+)$/im', $out, $m)) {
            $healthExplicit = true;
            $metrics['health'] = strtoupper(trim($m[1]));
        } elseif (preg_match('/SMART Health Status:\\s*(.+)$/im', $out, $m)) {
            $healthExplicit = true;
            $metrics['health'] = strtoupper(trim($m[1]));
        }

        foreach (preg_split('/\r?\n/', $out) as $line) {
            if (preg_match('/\\bReallocated_Sector_Ct\\b.*?\\s(\\d+)\\s*$/', $line, $m)) {
                $metrics['reallocated'] = (int) $m[1];
            }
            if (preg_match('/\\bCurrent_Pending_Sector\\b.*?\\s(\\d+)\\s*$/', $line, $m)) {
                $metrics['pending'] = (int) $m[1];
            }
            if (preg_match('/\\bUDMA_CRC_Error_Count\\b.*?\\s(\\d+)\\s*$/', $line, $m)) {
                $metrics['udma_crc'] = (int) $m[1];
                $metrics['link_errors'] = (int) $m[1];
            }
            if (preg_match('/\\bTemperature(_Celsius)?\\b.*?\\s(\\d+)\\s*$/', $line, $m)) {
                $metrics['temp_c'] = (int) $m[2];
            }
            if (preg_match('/^194\\s+Temperature_Celsius.*?\\s(\\d+)\\s*$/', $line, $m)) {
                $metrics['temp_c'] = (int) $m[1];
            }
            if (preg_match('/Current\\s+Drive\\s+Temperature:\\s*([0-9]+)\\s*C/i', $line, $m)) {
                $metrics['temp_c'] = (int) $m[1];
            }
            if (preg_match('/Elements\\s+in\\s+grown\\s+defect\\s+list:\\s*([0-9]+)/i', $line, $m)) {
                $metrics['reallocated'] = (int) $m[1];
            }
            if (preg_match('/Non-medium\\s+error\\s+count:\\s*([0-9]+)/i', $line, $m)) {
                $metrics['link_errors'] = (int) $m[1];
            }
            if (preg_match('/\\bPower_On_Hours\\b.*?\\s(\\d+)\\s*$/', $line, $m)) {
                $metrics['power_on_hours'] = (int) $m[1];
            }
            if (preg_match('/Accumulated\\s+power\\s+on\\s+time.*?([0-9]+):([0-9]+):([0-9]+)/i', $line, $m)) {
                $metrics['power_on_hours'] = (int) $m[1];
            }
        }

        $entry['metrics'] = $metrics;
        $flags = [];
        $sev = 'ok';

        $health = $metrics['health'];
        $healthOk = false;
        if (is_string($health)) {
            $h = strtoupper($health);
            if (strpos($h, 'PASSED') !== false || $h === 'OK' || strpos($h, 'OK') === 0) {
                $healthOk = true;
            }
            if (strpos($h, 'FAIL') !== false || strpos($h, 'BAD') !== false) {
                $healthOk = false;
            }
        }

        if ($healthExplicit) {
            if (!$healthOk) {
                $sev = pmssStorageHealthSeverityMax($sev, 'fail');
                $flags[] = 'health_not_ok';
            }
        } else {
            $sev = pmssStorageHealthSeverityMax($sev, 'warn');
            $flags[] = 'health_unknown';
        }

        if (($metrics['pending'] ?? 0) > 0) {
            $sev = pmssStorageHealthSeverityMax($sev, 'warn');
            $flags[] = 'pending_sectors';
        }
        if (($metrics['reallocated'] ?? 0) > 0) {
            $sev = pmssStorageHealthSeverityMax($sev, 'warn');
            $flags[] = 'reallocated_sectors';
        }

        $temp = $metrics['temp_c'];
        if (is_int($temp)) {
            $rota = (int) ($disk['rota'] ?? 1);
            $threshold = ($rota === 1) ? 50 : 70;
            if ($temp >= $threshold) {
                $sev = pmssStorageHealthSeverityMax($sev, 'warn');
                $flags[] = ($rota === 1) ? 'hot_hdd' : 'hot_ssd';
            }
        }

        if (is_array($prevMetrics)) {
            if (isset($metrics['reallocated'], $prevMetrics['reallocated']) && is_int($metrics['reallocated']) && is_int($prevMetrics['reallocated']) && $metrics['reallocated'] > $prevMetrics['reallocated']) {
                $flags[] = 'reallocated_increase';
            }
            if (isset($metrics['pending'], $prevMetrics['pending']) && is_int($metrics['pending']) && is_int($prevMetrics['pending']) && $metrics['pending'] > $prevMetrics['pending']) {
                $sev = pmssStorageHealthSeverityMax($sev, 'warn');
                $flags[] = 'pending_increase';
            }
            if (isset($metrics['link_errors'], $prevMetrics['link_errors']) && is_int($metrics['link_errors']) && is_int($prevMetrics['link_errors']) && $metrics['link_errors'] > $prevMetrics['link_errors']) {
                $flags[] = 'link_errors_increase';
            }
        }

        $entry['flags'] = $flags;
        $entry['severity'] = $sev;
        $entry['ok'] = ($sev === 'ok');
        return $entry;
    }
}

if (!function_exists('pmssStorageHealthSnapshotSmart')) {
    /**
     * @param array<string, mixed> $disk
     * @param array<string, mixed> $last
     * @return array<string, mixed>
     */
    function pmssStorageHealthSnapshotSmart(array $disk, array $last, string $timestamp): array
    {
        $dev = (string) $disk['path'];
        $base = [
            'timestamp' => $timestamp,
            'kind' => 'smart',
            'device' => $dev,
            'kname' => (string) ($disk['kname'] ?? ''),
            'model' => (string) ($disk['model'] ?? ''),
            'serial' => (string) ($disk['serial'] ?? ''),
            'rota' => (int) ($disk['rota'] ?? 1),
            'size' => (string) ($disk['size'] ?? ''),
            'ok' => false,
            'severity' => 'warn',
        ];

        if (!is_readable($dev)) {
            $base['error'] = 'device unreadable';
            $base['flags'] = ['device_unreadable'];
            return $base;
        }
        if (trim((string) shell_exec('command -v smartctl 2>/dev/null')) === '') {
            $base['error'] = 'smartctl missing';
            $base['flags'] = ['smartctl_missing'];
            return $base;
        }

        $cmd = 'smartctl -n standby,now -H -A -i '.escapeshellarg($dev);
        $res = pmssStorageHealthExecCapture($cmd, 25);
        $out = $res['stdout']."\n".$res['stderr'];
        if (trim($out) === '') {
            $base['error'] = 'smartctl produced no output';
            $base['flags'] = ['smartctl_empty'];
            return $base;
        }

        $prevMetrics = null;
        $prev = $last['smart::'.$dev]['metrics'] ?? null;
        if (is_array($prev)) {
            $prevMetrics = $prev;
            if (isset($prev['udma_crc']) && !isset($prev['link_errors'])) {
                $prevMetrics['link_errors'] = $prev['udma_crc'];
            }
        }

        $entry = pmssStorageHealthParseSmartctlOutput($out, $disk, $prevMetrics, $timestamp);
        if ($res['rc'] === 124) {
            $entry['severity'] = pmssStorageHealthSeverityMax((string) $entry['severity'], 'warn');
            $entry['ok'] = ($entry['severity'] === 'ok');
            $entry['flags'] = array_values(array_unique(array_merge((array) ($entry['flags'] ?? []), ['smartctl_timeout'])));
        }
        return $entry;
    }
}

