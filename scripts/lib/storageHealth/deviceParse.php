<?php
/** Pure SMART/NVMe text parsing and device findings. @license GPL-3.0-only */

require_once __DIR__.'/deviceMetrics.php';
require_once dirname(__DIR__).'/runtime/environment.php';

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

    $health = null;
    foreach ([
        '/SMART overall-health\\s+self-assessment\\s+test\\s+result:\\s*(.+)$/im',
        '/SMART Health Status:\\s*(.+)$/im',
    ] as $healthPattern) {
        if (preg_match($healthPattern, $out, $m) !== 1) {
            continue;
        }
        $metrics['health'] = $health = strtoupper(trim($m[1]));
        break;
    }

    // The first capture is always the counter; keep parser order for overlapping lines.
    $lineParsers = [
        '/\\bReallocated_Sector_Ct\\b.*?\\s(\\d+)\\s*$/' => ['reallocated'],
        '/\\bCurrent_Pending_Sector\\b.*?\\s(\\d+)\\s*$/' => ['pending'],
        '/\\bUDMA_CRC_Error_Count\\b.*?\\s(\\d+)\\s*$/' => ['udma_crc', 'link_errors'],
        '/\\bTemperature(?:_Celsius)?\\b.*?\\s(\\d+)\\s*$/' => ['temp_c'],
        '/Current\\s+Drive\\s+Temperature:\\s*([0-9]+)\\s*C/i' => ['temp_c'],
        '/Elements\\s+in\\s+grown\\s+defect\\s+list:\\s*([0-9]+)/i' => ['reallocated'],
        '/Non-medium\\s+error\\s+count:\\s*([0-9]+)/i' => ['link_errors'],
        '/\\bPower_On_Hours\\b.*?\\s(\\d+)\\s*$/' => ['power_on_hours'],
        '/Accumulated\\s+power\\s+on\\s+time.*?([0-9]+):([0-9]+):([0-9]+)/i' => ['power_on_hours'],
    ];
    $metrics = pmssStorageHealthMetricsParse(pmssTextLines($out), $lineParsers, $metrics);

    $entry['metrics'] = $metrics;
    // Null distinguishes an absent health line from an explicit, unsuccessful result.
    $flags = ($health === null || (strpos($health, 'PASSED') === false && strpos($health, 'OK') !== 0)
        || strpos($health, 'FAIL') !== false || strpos($health, 'BAD') !== false)
        ? [$health === null ? 'health_unknown' : 'health_not_ok'] : [];

    $rota = (int) ($disk['rota'] ?? 1);
    $flags = array_merge($flags, pmssStorageHealthMetricThresholdFlags($metrics, [
        'pending' => [1 => 'pending_sectors'], 'reallocated' => [1 => 'reallocated_sectors'],
        'temp_c' => [($rota === 1 ? 50 : 70) => ($rota === 1 ? 'hot_hdd' : 'hot_ssd')],
    ]), pmssStorageHealthMetricIncreaseFlags($metrics, $prevMetrics, ['reallocated' => 'reallocated_increase', 'pending' => 'pending_increase', 'link_errors' => 'link_errors_increase']));
    return pmssStorageHealthEntryFinalize($entry, $flags);
}

/** Parse one nonempty probe response; snapshots own device eligibility and command execution. */
function pmssStorageHealthParseNvmeOutput(string $out, array $disk, ?array $previous, string $timestamp): array
{
    $metrics = pmssStorageHealthMetricsParse([$out], [
        '/critical_warning\\s*:\\s*(\\d+)/i' => ['critical_warnings'],
        '/media_errors\\s*:\\s*(\\d+)/i' => ['media_errors'],
        '/num_err_log_entries\\s*:\\s*(\\d+)/i' => ['num_err_log_entries'],
        '/percentage_used\\s*:\\s*(\\d+)/i' => ['percentage_used'],
    ], array_fill_keys(['critical_warnings', 'temperature', 'media_errors', 'num_err_log_entries', 'percentage_used'], null));
    if (preg_match('/temperature\\s*:\\s*([0-9]+)\\s*([KC])?/i', $out, $matches)) {
        $metrics['temperature'] = (int) $matches[1] - (strtoupper($matches[2] ?? 'C') === 'K' ? 273 : 0);
    }

    $entry = pmssStorageHealthDeviceEntryBuild('nvme', $disk, $timestamp, 0);
    $entry['metrics'] = $metrics;
    $flags = array_merge(pmssStorageHealthMetricThresholdFlags($metrics, [
        'critical_warnings' => [1 => 'nvme_critical_warning'], 'temperature' => [70 => 'hot_nvme'],
        'percentage_used' => [95 => 'wearout_critical', 80 => 'wearout_high'],
    ]), pmssStorageHealthMetricIncreaseFlags($metrics, $previous, ['media_errors' => 'media_errors_increase', 'num_err_log_entries' => 'err_log_increase']));
    return pmssStorageHealthEntryFinalize($entry, $flags);
}
