<?php
/**
 * Passive host I/O estimates from immutable, completed UTC day buckets.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

const PMSS_IO_CEILING_DIMENSIONS = [
    'read_iops' => 'iopsRead', 'write_iops' => 'iopsWrite',
    'read_mbs' => 'throughputRead', 'write_mbs' => 'throughputWrite',
];

/** Validate tracker settings before allocating or publishing any estimates. */
function pmssIoCeilingSettings(array $policy): ?array
{
    $settings = $policy['ioCeiling'] ?? [];
    if (!is_array($settings)) {
        return null;
    }
    $settings += ['percentile' => 95, 'windowDays' => 7, 'minSamplesPerDay' => 144, 'minDays' => 3];
    foreach (['percentile' => 100, 'windowDays' => 31, 'minSamplesPerDay' => 288, 'minDays' => 31] as $key => $max) {
        if (!is_int($settings[$key]) || $settings[$key] < 1 || $settings[$key] > $max) {
            return null;
        }
    }
    return $settings['minDays'] <= $settings['windowDays'] ? $settings : null;
}

/** Compute nearest-rank daily percentiles, then their per-dimension maximum. */
function pmssIoCeilingCompute(iterable $samples, array $policy, int $now): ?array
{
    $settings = pmssIoCeilingSettings($policy);
    if ($settings === null || $now <= 0) {
        return null;
    }
    $end = intdiv($now, 86400) * 86400;
    $start = $end - $settings['windowDays'] * 86400;
    $buckets = [];
    foreach ($samples as $sample) {
        if (!is_array($sample) || !isset($sample['time']) || !is_int($sample['time'])
            || $sample['time'] < $start || $sample['time'] >= $end) {
            continue;
        }
        $values = [];
        foreach (PMSS_IO_CEILING_DIMENSIONS as $dimension => $field) {
            $value = $sample[$field] ?? null;
            if ((!is_int($value) && !is_float($value) && !is_string($value))
                || !is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
                continue 2;
            }
            $values[$dimension] = (float) $value;
        }
        // Epoch time, not the collector's local-time prefix, defines the UTC day.
        // Deduplicate overlapping rotations; repeated rows cannot satisfy the gate.
        $buckets[gmdate('Y-m-d', $sample['time'])][$sample['time']] = $values;
    }
    ksort($buckets);
    $days = [];
    $published = ['from_day' => []];
    foreach ($buckets as $day => $rows) {
        $count = count($rows);
        if ($count < $settings['minSamplesPerDay']) {
            continue;
        }
        $days[$day] = ['samples' => $count];
        foreach (PMSS_IO_CEILING_DIMENSIONS as $dimension => $field) {
            $values = array_column($rows, $dimension);
            sort($values, SORT_NUMERIC);
            $value = $values[(int) ceil($settings['percentile'] * $count / 100) - 1];
            $days[$day][$dimension.'_percentile'] = $value;
            if (!isset($published[$dimension]) || $value > $published[$dimension]) {
                $published[$dimension] = $value;
                $published['from_day'][$dimension] = $day;
            }
        }
    }
    if (count($days) < $settings['minDays']) {
        return null;
    }
    return [
        'computed_at' => gmdate('c', $now), 'percentile' => $settings['percentile'],
        'window_days' => $settings['windowDays'], 'min_samples_per_day' => $settings['minSamplesPerDay'],
        'min_days' => $settings['minDays'], 'excluded_current_day' => gmdate('Y-m-d', $end),
        'aggregation_frame' => 'grp1 aggregate across diskIostat.php matched physical devices; 120s averages every 5 minutes',
        'days' => $days, 'published' => $published,
    ];
}
