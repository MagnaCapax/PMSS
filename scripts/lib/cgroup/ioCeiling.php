<?php
/**
 * Passive host I/O estimates from immutable, completed UTC day buckets.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/runtime.php';

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
    $days = [];
    $pings = [];
    foreach ($samples as $sample) {
        if (!is_array($sample) || !isset($sample['time']) || !is_int($sample['time'])
            || $sample['time'] < $start || $sample['time'] >= $end) {
            continue;
        }
        // The /home read latency is kept apart: a missed probe must not drop the row from the ceiling.
        $ping = pmssFiniteNonNegativeFloat($sample['iopingHomeMs'] ?? null);
        if ($ping !== null && $ping <= 60000) $pings[gmdate('Y-m-d', $sample['time'])][$sample['time']] = $ping;
        $values = [];
        foreach (PMSS_IO_CEILING_DIMENSIONS as $field) {
            if (($values[$field] = pmssFiniteNonNegativeFloat($sample[$field] ?? null)) === null) {
                continue 2;
            }
        }
        // Epoch time, not the collector's local-time prefix, defines the UTC day.
        // Deduplicate overlapping rotations; repeated rows cannot satisfy the gate.
        $days[gmdate('Y-m-d', $sample['time'])][$sample['time']] = $values;
    }
    ksort($days);
    $published = ['from_day' => []];
    // Replace accepted buckets with their published summary in the same day order.
    foreach ($days as $day => $rows) {
        $count = count($rows);
        if ($count < $settings['minSamplesPerDay']) {
            unset($days[$day]);
            continue;
        }
        $days[$day] = ['samples' => $count];
        $rank = (int) ceil($settings['percentile'] * $count / 100) - 1;
        foreach (PMSS_IO_CEILING_DIMENSIONS as $dimension => $field) {
            $values = array_column($rows, $field);
            sort($values, SORT_NUMERIC);
            $value = $values[$rank];
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
    // Quiet floor of the disk (ADR 0237): the median over accepted days of each day's 10th-percentile latency.
    $floors = [];
    foreach (array_keys($days) as $day) {
        $values = array_values($pings[$day] ?? []);
        if (count($values) < $settings['minSamplesPerDay']) continue;
        sort($values, SORT_NUMERIC);
        $floors[] = $values[(int) ceil(10 * count($values) / 100) - 1];
    }
    sort($floors, SORT_NUMERIC);
    $n = count($floors);
    $floor = $n === 0 ? null : ($n % 2 === 1 ? $floors[intdiv($n, 2)] : ($floors[$n / 2 - 1] + $floors[$n / 2]) / 2);
    return [
        'computed_at' => gmdate('c', $now), 'percentile' => $settings['percentile'],
        'window_days' => $settings['windowDays'], 'min_samples_per_day' => $settings['minSamplesPerDay'],
        'min_days' => $settings['minDays'], 'excluded_current_day' => gmdate('Y-m-d', $end),
        'aggregation_frame' => 'grp1 aggregate across diskIostat.php matched physical devices; 120s averages every 5 minutes',
        'days' => $days, 'published' => $published, 'ioping_floor_ms' => $floor,
    ];
}
