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
    $entry = pmssStorageHealthDeviceEntryBuild('smart', $disk, $timestamp, 1);

    $metrics = ['health' => 'UNKNOWN'] + array_fill_keys(['reallocated', 'pending', 'udma_crc', 'temp_c', 'power_on_hours', 'link_errors'], null);

    if (stripos($out, 'Device is in STANDBY') !== false || stripos($out, 'Device is in SLEEP') !== false) {
        $entry['metrics'] = $metrics;
        $entry['metrics']['health'] = 'STANDBY';
        return pmssStorageHealthEntryFinalize($entry, ['standby']);
    }

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

    // The first capture is always the counter; keep parser order for overlapping lines.
    $lineParsers = [
        '/\\bReallocated_Sector_Ct\\b.*?\\s(\\d+)\\s*$/' => ['reallocated'],
        '/\\bCurrent_Pending_Sector\\b.*?\\s(\\d+)\\s*$/' => ['pending'],
        '/\\bUDMA_CRC_Error_Count\\b.*?\\s(\\d+)\\s*$/' => ['udma_crc', 'link_errors'],
        '/\\bTemperature(?:_Celsius)?\\b.*?\\s(\\d+)\\s*$/' => ['temp_c'],
        '/^194\\s+Temperature_Celsius.*?\\s(\\d+)\\s*$/' => ['temp_c'],
        '/Current\\s+Drive\\s+Temperature:\\s*([0-9]+)\\s*C/i' => ['temp_c'],
        '/Elements\\s+in\\s+grown\\s+defect\\s+list:\\s*([0-9]+)/i' => ['reallocated'],
        '/Non-medium\\s+error\\s+count:\\s*([0-9]+)/i' => ['link_errors'],
        '/\\bPower_On_Hours\\b.*?\\s(\\d+)\\s*$/' => ['power_on_hours'],
        '/Accumulated\\s+power\\s+on\\s+time.*?([0-9]+):([0-9]+):([0-9]+)/i' => ['power_on_hours'],
    ];
    foreach (preg_split('/\r?\n/', $out) as $line) {
        foreach ($lineParsers as $pattern => $targets) {
            if (preg_match($pattern, $line, $matches) !== 1) {
                continue;
            }
            foreach ($targets as $target) {
                $metrics[$target] = (int) $matches[1];
            }
        }
    }

    $entry['metrics'] = $metrics;
    $flags = [];

    $health = $metrics['health'];
    $healthUpper = is_string($health) ? strtoupper($health) : '';
    $healthOk = $healthUpper !== ''
        && (strpos($healthUpper, 'PASSED') !== false || $healthUpper === 'OK' || strpos($healthUpper, 'OK') === 0)
        && strpos($healthUpper, 'FAIL') === false
        && strpos($healthUpper, 'BAD') === false;

    if (!$healthExplicit || !$healthOk) {
        $flags[] = $healthExplicit ? 'health_not_ok' : 'health_unknown';
    }

    foreach (['pending' => 'pending_sectors', 'reallocated' => 'reallocated_sectors'] as $metric => $flag) {
        if (($metrics[$metric] ?? 0) <= 0) {
            continue;
        }
        $flags[] = $flag;
    }

    $temp = $metrics['temp_c'];
    if (is_int($temp)) {
        $rota = (int) ($disk['rota'] ?? 1);
        $threshold = ($rota === 1) ? 50 : 70;
        if ($temp >= $threshold) {
            $flags[] = ($rota === 1) ? 'hot_hdd' : 'hot_ssd';
        }
    }

    $flags = array_merge($flags, pmssStorageHealthMetricIncreaseFlags($metrics, $prevMetrics, ['reallocated' => 'reallocated_increase', 'pending' => 'pending_increase', 'link_errors' => 'link_errors_increase']));
    return pmssStorageHealthEntryFinalize($entry, $flags);
}

/**
 * @param array<string, mixed> $disk
 * @param array<string, mixed> $last
 * @return array<string, mixed>
 */
function pmssStorageHealthSnapshotSmart(array $disk, array $last, string $timestamp): array
{
    $dev = (string) $disk['path'];
    if (!is_readable($dev)) {
        return pmssStorageHealthEntryFinalize(pmssStorageHealthDeviceEntryBuild('smart', $disk, $timestamp, 1), ['device_unreadable'], 'device unreadable');
    }
    if (pmssCommandPath('smartctl') === '') {
        return pmssStorageHealthEntryFinalize(pmssStorageHealthDeviceEntryBuild('smart', $disk, $timestamp, 1), ['smartctl_missing'], 'smartctl missing');
    }

    $cmd = 'smartctl -n standby,now -H -A -i '.escapeshellarg($dev);
    $probe = pmssStorageHealthProbeCommand('smart', (string) ($disk['kname'] ?? ''), $cmd);
    $res = pmssCommandCapture($probe['command'], 25);
    $out = $res['stdout']."\n".$res['stderr'];
    if (trim($out) === '') {
        if ($probe['lock_exit_code'] !== 0 && (int) $res['rc'] === $probe['lock_exit_code']) {
            return pmssStorageHealthEntryFinalize(pmssStorageHealthDeviceEntryBuild('smart', $disk, $timestamp, 1), ['smartctl_probe_locked'], 'smartctl probe already running');
        }
        return pmssStorageHealthEntryFinalize(pmssStorageHealthDeviceEntryBuild('smart', $disk, $timestamp, 1), ['smartctl_empty'], 'smartctl produced no output');
    }

    $prevMetrics = $last['smart::'.$dev]['metrics'] ?? null;
    if (is_array($prevMetrics) && isset($prevMetrics['udma_crc']) && !isset($prevMetrics['link_errors'])) {
        $prevMetrics['link_errors'] = $prevMetrics['udma_crc'];
    }

    $entry = pmssStorageHealthParseSmartctlOutput($out, $disk, $prevMetrics, $timestamp);
    if ($res['rc'] === 124) {
        return pmssStorageHealthEntryFinalize($entry, array_merge((array) ($entry['flags'] ?? []), ['smartctl_timeout']));
    }
    return $entry;
}
