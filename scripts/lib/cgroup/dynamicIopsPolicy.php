<?php
/**
 * Pure shadow read-IOPS allocation; results are never enforcement inputs.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/policy.php';

/** Reject malformed knobs rather than silently changing the experiment. */
function pmssDynamicIopsSettings(array $policy): ?array
{
    $settings = $policy['cgroup']['dynamicIops'] ?? [];
    if (!is_array($settings)) return null;
    $settings += ['mode' => 'off', 'idlePressure' => 5.0, 'busyPressure' => 15.0,
        'idleMultiplier' => 10.0, 'busyMultiplier' => 2.0, 'floor' => 15, 'floorCharge' => 0.25];
    if (!in_array($settings['mode'], ['off', 'shadow'], true)) return null;
    foreach (['idlePressure', 'busyPressure', 'idleMultiplier', 'busyMultiplier', 'floor', 'floorCharge'] as $key) {
        $settings[$key] = pmssFiniteNonNegativeFloat($settings[$key]);
        if ($settings[$key] === null) return null;
    }
    return $settings['idlePressure'] < $settings['busyPressure'] && $settings['busyPressure'] <= 100
        && $settings['busyMultiplier'] >= 2 && $settings['idleMultiplier'] >= $settings['busyMultiplier']
        && $settings['idleMultiplier'] <= 100 && $settings['floor'] >= 1 && $settings['floor'] <= 1000000
        && $settings['floorCharge'] <= 1 ? $settings : null;
}

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
    foreach (['io' => 'psiFullAvg300', 'memory' => 'psiMemFullAvg300', 'cpu' => 'psiCpuFullAvg300'] as $kind => $field) {
        foreach ([5 => 1, 15 => 3, 60 => 12] as $minutes => $count) {
            $values = [];
            for ($n = 0; $n < $count; $n++) {
                $value = pmssFiniteNonNegativeFloat($rows[$n][$field] ?? null);
                if ($value === null || $value > 100) break;
                $values[] = $value;
            }
            $pressure[$kind][$minutes] = count($values) === $count ? array_sum($values) / $count : null;
        }
    }
    $io = $pressure['io'];
    $pressure['blend'] = in_array(null, $io, true) ? null : (12 * $io[5] + 4 * $io[15] + $io[60]) / 17;
    return $pressure;
}

/** Compare quota exponents using already bonus-adjusted BFQ weights. */
function pmssDynamicIopsAllocate(array $users, array $policy, array $pressure, ?float $passive, ?float $benchmark, ?string $storageClass, bool $legacyAllowed, ?float $legacyPassive = null): ?array
{
    $settings = pmssDynamicIopsSettings($policy);
    if ($settings === null) return null;
    $blend = pmssFiniteNonNegativeFloat($pressure['blend'] ?? null);
    if ($blend !== null && $blend > 100) $blend = null;
    $fraction = $blend === null ? 1.0 : max(0.0, min(1.0,
        ($blend - $settings['idlePressure']) / ($settings['busyPressure'] - $settings['idlePressure'])));
    $multiplier = $settings['idleMultiplier'] + $fraction * ($settings['busyMultiplier'] - $settings['idleMultiplier']);
    $passive = pmssFiniteNonNegativeFloat($passive);
    $benchmark = pmssFiniteNonNegativeFloat($benchmark);
    $host = max($passive ?? 0.0, $benchmark ?? 0.0);
    $pool = $host * $multiplier;
    if (!is_finite($pool) || $pool >= PHP_INT_MAX) return null;
    $sources = array_column($users, 'source');
    $weightSum = pmssCgroupPolicyUserIoWeightSum($sources, $policy);
    $quotas = [];
    foreach ($users as $user => $entry) {
        $quota = pmssFiniteNonNegativeFloat($entry['source']['quota'] ?? null);
        $weight = pmssFiniteNonNegativeFloat($entry['weight'] ?? null);
        if ($quota === null || $quota <= 0 || $weight === null || $weight <= 0 || $weight > PMSS_BFQ_KERNEL_MAX) continue;
        $quotas[$user] = $quota;
    }
    // Incomplete cohort data must not redistribute other accounts' shares.
    $fallback = $host <= 0 ? 'missing-ceiling' : ($blend === null ? 'missing-pressure' :
        (count($quotas) !== count($users) ? 'missing-quota-or-weight' : null));
    $sorted = array_values($quotas);
    sort($sorted, SORT_NUMERIC);
    $count = count($sorted);
    $low = $count === 0 ? 1.0 : $sorted[intdiv($count - 1, 2)];
    $median = $count === 0 ? 1.0 : $low + ($sorted[intdiv($count, 2)] - $low) / 2;
    $result = ['pressure' => $pressure, 'passive_read_iops' => $passive, 'benchmark_read_iops' => $benchmark,
        'host_read_iops' => $host, 'multiplier' => $multiplier, 'pool' => $pool, 'fallback' => $fallback, 'variants' => []];
    foreach (['1.0' => 1.0, '0.5' => 0.5] as $label => $alpha) {
        $weights = []; $rows = []; $lift = 0.0;
        foreach ($users as $user => $entry) {
            $weights[$user] = (pmssFiniteNonNegativeFloat($entry['weight'] ?? null) ?? 0) * pow(($quotas[$user] ?? $median) / $median, $alpha);
        }
        $sum = array_sum($weights);
        if (!is_finite($sum)) return null;
        foreach ($users as $user => $entry) {
            $source = $entry['source'];
            $floor = pmssCgroupPolicyPositiveIntSetting($source['resourceIOPSReadMin'] ?? null) ?? (int) $settings['floor'];
            $share = $sum > 0 ? $weights[$user] / $sum : 0.0;
            $explicit = isset($source['IOReadIOPS']) && is_scalar($source['IOReadIOPS']);
            $rows[$user] = ['quota' => $quotas[$user] ?? null, 'weight' => $entry['weight'] ?? null,
                'share' => $share, 'floor' => $floor, 'ceiling' => pmssCgroupPolicyReadIopsTierCeiling($source, $policy),
                'explicit' => $explicit, 'legacy' => pmssCgroupPolicyDerivedReadIopsCap($source, $policy,
                    $legacyPassive ?? $passive, $weightSum, count($users), $storageClass, $legacyAllowed)];
            if (!$explicit) $lift += max(0.0, $floor - $pool * $share);
        }
        $chargedPool = max(0.0, $pool - $settings['floorCharge'] * $lift);
        foreach ($rows as &$row) {
            $value = max($row['floor'], $chargedPool * $row['share']);
            $rounded = round($value, 1 - (int) floor(log10(max(1.0, $value))));
            $row['proposed'] = $fallback !== null || $row['explicit'] ? $row['legacy'] :
                min($row['ceiling'] ?? PHP_INT_MAX, max($row['floor'], (int) min($rounded, PHP_INT_MAX - 1024)));
        }
        unset($row);
        $result['variants'][$label] = ['alpha' => $alpha, 'floor_lift' => $lift, 'charged_pool' => $chargedPool, 'users' => $rows];
    }
    return $result;
}
