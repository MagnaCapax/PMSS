<?php
/**
 * SMART parsing + snapshot helpers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

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
    ];

    $defaultMetrics = [
        'health' => 'UNKNOWN',
        'reallocated' => null,
        'pending' => null,
        'udma_crc' => null,
        'temp_c' => null,
        'power_on_hours' => null,
        'link_errors' => null,
    ];

    if (stripos($out, 'Device is in STANDBY') !== false || stripos($out, 'Device is in SLEEP') !== false) {
        $entry['metrics'] = $defaultMetrics;
        $entry['metrics']['health'] = 'STANDBY';
        $entry['flags'] = ['standby'];
        $entry['severity'] = 'ok';
        $entry['ok'] = true;
        return $entry;
    }

    $metrics = $defaultMetrics;

    $healthExplicit = false;
    foreach ([
        '/SMART overall-health\\s+self-assessment\\s+test\\s+result:\\s*(.+)$/im',
        '/SMART Health Status:\\s*(.+)$/im',
    ] as $healthPattern) {
        if (preg_match($healthPattern, $out, $m) !== 1) {
            continue;
        }
        $healthExplicit = true;
        $metrics['health'] = strtoupper(trim($m[1]));
        break;
    }

    $lineParsers = [
        ['pattern' => '/\\bReallocated_Sector_Ct\\b.*?\\s(\\d+)\\s*$/', 'group' => 1, 'targets' => ['reallocated']],
        ['pattern' => '/\\bCurrent_Pending_Sector\\b.*?\\s(\\d+)\\s*$/', 'group' => 1, 'targets' => ['pending']],
        ['pattern' => '/\\bUDMA_CRC_Error_Count\\b.*?\\s(\\d+)\\s*$/', 'group' => 1, 'targets' => ['udma_crc', 'link_errors']],
        ['pattern' => '/\\bTemperature(_Celsius)?\\b.*?\\s(\\d+)\\s*$/', 'group' => 2, 'targets' => ['temp_c']],
        ['pattern' => '/^194\\s+Temperature_Celsius.*?\\s(\\d+)\\s*$/', 'group' => 1, 'targets' => ['temp_c']],
        ['pattern' => '/Current\\s+Drive\\s+Temperature:\\s*([0-9]+)\\s*C/i', 'group' => 1, 'targets' => ['temp_c']],
        ['pattern' => '/Elements\\s+in\\s+grown\\s+defect\\s+list:\\s*([0-9]+)/i', 'group' => 1, 'targets' => ['reallocated']],
        ['pattern' => '/Non-medium\\s+error\\s+count:\\s*([0-9]+)/i', 'group' => 1, 'targets' => ['link_errors']],
        ['pattern' => '/\\bPower_On_Hours\\b.*?\\s(\\d+)\\s*$/', 'group' => 1, 'targets' => ['power_on_hours']],
        ['pattern' => '/Accumulated\\s+power\\s+on\\s+time.*?([0-9]+):([0-9]+):([0-9]+)/i', 'group' => 1, 'targets' => ['power_on_hours']],
    ];
    foreach (preg_split('/\r?\n/', $out) as $line) {
        foreach ($lineParsers as $parser) {
            if (preg_match($parser['pattern'], $line, $matches) !== 1) {
                continue;
            }
            $value = (int) $matches[$parser['group']];
            foreach ($parser['targets'] as $target) {
                $metrics[$target] = $value;
            }
        }
    }

    $entry['metrics'] = $metrics;
    $flags = [];
    $sev = 'ok';

    $health = $metrics['health'];
    $healthUpper = is_string($health) ? strtoupper($health) : '';
    $healthOk = $healthUpper !== ''
        && (strpos($healthUpper, 'PASSED') !== false || $healthUpper === 'OK' || strpos($healthUpper, 'OK') === 0)
        && strpos($healthUpper, 'FAIL') === false
        && strpos($healthUpper, 'BAD') === false;

    if (!$healthExplicit) {
        if ($sev === 'ok') { $sev = 'warn'; }
        $flags[] = 'health_unknown';
    } elseif (!$healthOk) {
        $sev = 'fail';
        $flags[] = 'health_not_ok';
    }

    foreach (['pending' => 'pending_sectors', 'reallocated' => 'reallocated_sectors'] as $metric => $flag) {
        if (($metrics[$metric] ?? 0) <= 0) {
            continue;
        }
        if ($sev === 'ok') { $sev = 'warn'; }
        $flags[] = $flag;
    }

    $temp = $metrics['temp_c'];
    if (is_int($temp)) {
        $rota = (int) ($disk['rota'] ?? 1);
        $threshold = ($rota === 1) ? 50 : 70;
        if ($temp >= $threshold) {
            if ($sev === 'ok') { $sev = 'warn'; }
            $flags[] = ($rota === 1) ? 'hot_hdd' : 'hot_ssd';
        }
    }

    if (is_array($prevMetrics)) {
        foreach (['reallocated' => false, 'pending' => true, 'link_errors' => false] as $metric => $raiseWarn) {
            $current = $metrics[$metric] ?? null;
            $previous = $prevMetrics[$metric] ?? null;
            if (!is_int($current) || !is_int($previous) || $current <= $previous) {
                continue;
            }
            if ($raiseWarn) {
                if ($sev === 'ok') { $sev = 'warn'; }
            }
            $flags[] = $metric.'_increase';
        }
    }

    $entry['flags'] = $flags;
    $entry['severity'] = $sev;
    $entry['ok'] = ($sev === 'ok');
    return $entry;
}

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
        return $base + ['error' => 'device unreadable', 'flags' => ['device_unreadable']];
    }
    if (trim((string) shell_exec('command -v smartctl 2>/dev/null')) === '') {
        return $base + ['error' => 'smartctl missing', 'flags' => ['smartctl_missing']];
    }

    $cmd = 'smartctl -n standby,now -H -A -i '.escapeshellarg($dev);
    $res = pmssStorageHealthExecCapture($cmd, 25);
    $out = $res['stdout']."\n".$res['stderr'];
    if (trim($out) === '') {
        return $base + ['error' => 'smartctl produced no output', 'flags' => ['smartctl_empty']];
    }

    $prevMetrics = $last['smart::'.$dev]['metrics'] ?? null;
    if (is_array($prevMetrics) && isset($prevMetrics['udma_crc']) && !isset($prevMetrics['link_errors'])) {
        $prevMetrics['link_errors'] = $prevMetrics['udma_crc'];
    }

    $entry = pmssStorageHealthParseSmartctlOutput($out, $disk, $prevMetrics, $timestamp);
    if ($res['rc'] === 124) {
        if (($entry['severity'] ?? 'ok') === 'ok') { $entry['severity'] = 'warn'; }
        $entry['ok'] = false;
        $entry['flags'] = array_values(array_unique(array_merge((array) ($entry['flags'] ?? []), ['smartctl_timeout'])));
    }
    return $entry;
}
