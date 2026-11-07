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
    $io = $pressure['io'];
    $pressure['blend'] = in_array(null, $io, true) ? null : (12 * $io[5] + 4 * $io[15] + $io[60]) / 17;
    $iowait = $pressure['iowait'];
    $pressure['iowait_blend'] = in_array(null, $iowait, true) ? null : (12 * $iowait[5] + 4 * $iowait[15] + $iowait[60]) / 17;
    // Mean of the valid values in the last three buckets; one missed sample must not drop the input for three cycles.
    // latency_ms is the read wait the /home disks served (iostat r_await, ADR 0238). The O_DIRECT ioping median reads
    // a 1 MiB working set, mostly from cache on a busy array, so it is kept only for the solid-state band (ADR 0237).
    foreach (['latency_ms' => 'diskAwait', 'ioping_ms' => 'iopingHomeMs'] as $key => $field) {
        $sum = 0.0; $count = 0;
        for ($n = 0; $n < 3; $n++) {
            $value = pmssFiniteNonNegativeFloat($rows[$n][$field] ?? null);
            if ($value === null || $value > 60000) continue;
            $sum += $value; $count++;
        }
        $pressure[$key] = $count > 0 ? $sum / $count : null;
    }
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
