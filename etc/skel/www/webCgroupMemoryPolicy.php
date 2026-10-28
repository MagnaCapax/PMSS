<?php
/** Pure memory-pressure and OOM-recency policy, independent of acquisition.
 * @license GPL-3.0-only
 */

/** Only a sampled increase within 24 hours can make a lifetime v1 counter live. */
function pmssWebCgroupMemoryStatusOomRecent($count, $state, $now)
{
    if (!is_int($count) || $count <= 0 || !is_int($now) || $now < 0 || !is_array($state)) return false;
    $sampled = $state['sampled_at'] ?? null;
    $baseline = $state['count'] ?? null;
    $increase = $state['last_increase'] ?? null;
    if (!is_int($sampled) || $sampled > $now || $sampled < $now - 900
        || !is_int($baseline) || $baseline < 0 || $count < $baseline) return false;
    if ($count > $baseline) return true; // Kill since the collector's latest sample.
    return is_int($increase) && $increase <= $now && $increase > $now - 86400;
}

/** Classify current cgroup memory pressure into a user-facing level. */
function pmssWebCgroupMemoryStatusClassify(array $stats)
{
    if (!empty($stats['oom_kill_recent'])) {
        return 'HIGH';
    }
    if ($stats['memory_current'] !== null
        && $stats['memory_high'] !== null
        && $stats['throttle_events'] > 0
        && ($stats['memory_current'] >= $stats['memory_high']
            || ($stats['throttle_events'] > 1000 && $stats['high_percent'] !== null && $stats['high_percent'] >= 95.0))) {
        return 'THROTTLED';
    }

    // Ordered bands preserve severity precedence and each signal's exact cutoff.
    foreach ([
        'HIGH' => ['pressure_full_avg10' => 0.10, 'pressure_some_avg10' => 1.0, 'usage_percent' => 95.0, 'high_percent' => 95.0],
        'MEDIUM' => ['pressure_some_avg10' => 0.25, 'usage_percent' => 80.0, 'high_percent' => 80.0],
    ] as $status => $thresholds) {
        foreach ($thresholds as $field => $threshold) {
            if ($stats[$field] !== null && $stats[$field] >= $threshold) return $status;
        }
    }

    return 'LOW';
}
