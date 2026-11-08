<?php
/** Pure five-minute pressure windows for shadow IOPS. @license GPL-3.0-only */
require_once dirname(__DIR__).'/runtime.php';

/** Require distinct, recent five-minute buckets; duplicates cannot fill gaps. */
function pmssDynamicIopsPressure(iterable $samples, int $now): array
{
    $rows = [];
    foreach ($samples as $sample) {
        if (!is_array($sample) || !is_int($sample['time'] ?? null)
            || $sample['time'] > $now || $sample['time'] <= $now - 3600) continue;
        $bucket = intdiv($now - $sample['time'], 300);
        if (!isset($rows[$bucket]) || $sample['time'] > $rows[$bucket]['time']) $rows[$bucket] = $sample;
    }
    $pressure = [];
    foreach (['io' => 'psiFullAvg300', 'memory' => 'psiMemFullAvg300', 'cpu' => 'psiCpuFullAvg300',
        'iowait' => 'cpuIowaitPct'] as $kind => $field) {
        // One contiguous prefix supplies every window; a gap leaves later windows unavailable.
        $pressure[$kind] = [5 => null, 15 => null, 60 => null];
        $sum = 0.0; $n = 0;
        foreach ([5 => 1, 15 => 3, 60 => 12] as $minutes => $count) {
            for (; $n < $count; $n++) {
                $value = pmssFiniteNonNegativeFloat($rows[$n][$field] ?? null);
                if ($value === null || $value > 100) break 2;
                $sum += $value;
            }
            $pressure[$kind][$minutes] = $sum / $count;
        }
    }
    foreach (['blend' => 'io', 'iowait_blend' => 'iowait'] as $field => $kind) {
        $windows = $pressure[$kind];
        $pressure[$field] = in_array(null, $windows, true) ? null : (12 * $windows[5] + 4 * $windows[15] + $windows[60]) / 17;
    }
    // /home small-read latency (O_DIRECT ioping): mean of the valid probes in the last three buckets.
    // One probe is noisy; one missed probe must not drop the input for three cycles (ADR 0236).
    $sum = 0.0; $count = 0;
    for ($n = 0; $n < 3; $n++) {
        $value = pmssFiniteNonNegativeFloat($rows[$n]['iopingHomeMs'] ?? null);
        if ($value === null || $value > 60000) continue;
        $sum += $value; $count++;
    }
    $pressure['latency_ms'] = $count > 0 ? $sum / $count : null;
    $pressure['md_sync'] = $rows[0]['mdSyncActive'] ?? null;
    return $pressure;
}

/**
 * Solid-state latency reference (ADR 0237): the service group's median read latency, delivered daily by the
 * brain into fleetCompare.json and used for three days, or this disk's own quiet floor when that is higher.
 * A disk that answers slowly even when idle is judged against itself, not braked for its hardware.
 */
function pmssDynamicIopsLatencyReference(?array $fleetCompare, ?float $ownFloor, int $now): ?float
{
    $ts = $fleetCompare['ts'] ?? null;
    $group = is_int($ts) && $ts <= $now && $now - $ts <= 3 * 86400
        ? pmssFiniteNonNegativeFloat($fleetCompare['groupIopingMs'] ?? null) : null;
    $candidates = [];
    foreach ([$group, pmssFiniteNonNegativeFloat($ownFloor)] as $value) {
        if ($value !== null && $value > 0 && $value <= 60000) $candidates[] = $value;
    }
    return $candidates === [] ? null : max($candidates);
}
