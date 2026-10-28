<?php
/** Pure quota variants projected from one shadow account cohort. Loaded by dynamicIopsPolicy.php. @license GPL-3.0-only */

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
    $weightSum = pmssCgroupPolicyUserIoWeightSum(array_column($users, 'source'), $policy);
    $cohort = [];
    foreach ($users as $user => $entry) {
        $source = $entry['source'];
        $quota = pmssFiniteNonNegativeFloat($source['quota'] ?? null);
        $weight = pmssFiniteNonNegativeFloat($entry['weight'] ?? null);
        // Account facts and legacy caps do not depend on the quota exponent.
        // Reserve share's payload position for weight calculation and normalization.
        $cohort[$user] = ['quota' => $quota !== null && $quota > 0 && $weight !== null && $weight > 0 && $weight <= PMSS_BFQ_KERNEL_MAX ? $quota : null,
            'weight' => $entry['weight'] ?? null,
            'share' => 0.0, 'floor' => pmssCgroupPolicyPositiveIntSetting($source['resourceIOPSReadMin'] ?? null) ?? (int) $settings['floor'],
            'ceiling' => pmssCgroupPolicyReadIopsTierCeiling($source, $policy),
            'explicit' => isset($source['IOReadIOPS']) && is_scalar($source['IOReadIOPS']),
            'legacy' => pmssCgroupPolicyDerivedReadIopsCap($source, $policy,
                $legacyPassive ?? $passive, $weightSum, count($users), $storageClass, $legacyAllowed)];
    }
    // Valid cohort quotas are positive; only missing values drop out of the median.
    $sorted = array_filter(array_column($cohort, 'quota'));
    sort($sorted, SORT_NUMERIC);
    $count = count($sorted);
    // Incomplete cohort data must not redistribute other accounts' shares.
    $fallback = $host <= 0 ? 'missing-ceiling' : ($blend === null ? 'missing-pressure' :
        ($count !== count($users) ? 'missing-quota-or-weight' : null));
    $low = $count === 0 ? 1.0 : $sorted[intdiv($count - 1, 2)];
    $median = $count === 0 ? 1.0 : $low + ($sorted[intdiv($count, 2)] - $low) / 2;
    $result = ['pressure' => $pressure, 'passive_read_iops' => $passive, 'benchmark_read_iops' => $benchmark,
        'host_read_iops' => $host, 'multiplier' => $multiplier, 'pool' => $pool, 'fallback' => $fallback, 'variants' => []];
    foreach (['1.0' => 1.0, '0.5' => 0.5] as $label => $alpha) {
        $rows = $cohort; $lift = 0.0;
        foreach ($users as $user => $entry) {
            $rows[$user]['share'] = (pmssFiniteNonNegativeFloat($entry['weight'] ?? null) ?? 0) * pow(($rows[$user]['quota'] ?? $median) / $median, $alpha);
        }
        $sum = array_sum(array_column($rows, 'share'));
        if (!is_finite($sum)) return null;
        foreach ($rows as &$row) {
            $row['share'] = $sum > 0 ? $row['share'] / $sum : 0.0;
            if (!$row['explicit']) $lift += max(0.0, $row['floor'] - $pool * $row['share']);
        }
        unset($row);
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
