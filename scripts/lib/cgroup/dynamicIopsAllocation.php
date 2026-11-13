<?php
/** Pure quota variants projected from one account cohort. Loaded by dynamicIopsPolicy.php. @license GPL-3.0-only */
require_once __DIR__.'/directApply.php';
require_once __DIR__.'/dynamicIopsWorkConserving.php';
require_once __DIR__.'/dynamicIopsPool.php';

/** Keep identical floor, two-significant-figure rounding and ceiling for both projections. */
function pmssDynamicIopsProposal(array $row, float $chargedPool, float $water = 0.0): int
{
    $value = max($row['floor'], $chargedPool * $row['share'], $water);
    $rounded = max($row['floor'], round($value, 1 - (int) floor(log10(max(1.0, $value)))));
    // The kernel throttle delivers whole tens per second, so round up to the next ten it delivers.
    return min($row['ceiling'] ?? PHP_INT_MAX, (int) min(ceil($rounded / 10) * 10, PHP_INT_MAX - 1024));
}

/** Compare quota exponents using already bonus-adjusted BFQ weights. */
function pmssDynamicIopsAllocate(array $users, array $policy, array $pressure, ?float $passive, ?float $benchmark, ?string $storageClass, bool $legacyAllowed, ?float $legacyPassive = null, ?array $rates = null, ?array $wantsMore = null): ?array
{
    $settings = pmssDynamicIopsSettings($policy);
    if ($settings === null) return null;
    $result = pmssDynamicIopsPool($settings, $pressure, $passive, $benchmark);
    if ($result === null) return null;
    $pool = $result['pool'];
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
                $legacyPassive ?? $result['passive_read_iops'], $weightSum, count($users), $storageClass, $legacyAllowed)];
    }
    // Valid cohort quotas are positive; only missing values drop out of the median.
    $sorted = array_filter(array_column($cohort, 'quota'));
    sort($sorted, SORT_NUMERIC);
    $count = count($sorted);
    // Incomplete cohort data must not redistribute other accounts' shares. The probe's multiplier does
    // not come from the blend, so a late iostat sample cannot stop it (ADR 0238).
    $fallback = $result['fallback'] ?? ($count !== count($users) ? 'missing-quota-or-weight' : null);
    $result['fallback'] = $fallback;
    $low = $count === 0 ? 1.0 : $sorted[intdiv($count - 1, 2)];
    $median = $count === 0 ? 1.0 : $low + ($sorted[intdiv($count, 2)] - $low) / 2;
    foreach (['1.0' => 1.0, '0.5' => 0.5] as $label => $alpha) {
        $rows = $cohort;
        foreach ($rows as $user => $row) {
            $rows[$user]['share'] = (pmssFiniteNonNegativeFloat($row['weight']) ?? 0) * pow(($row['quota'] ?? $median) / $median, $alpha);
        }
        $sum = array_sum(array_column($rows, 'share'));
        if (!is_finite($sum)) return null;
        $lifts = ['proposed' => 0.0, 'would_be' => 0.0];
        foreach ($rows as &$row) {
            $row['share'] = $sum > 0 ? $row['share'] / $sum : 0.0;
            $shortfall = max(0.0, $row['floor'] - $pool * $row['share']);
            if (!$row['explicit']) $lifts['proposed'] += $shortfall;
            // Counterfactual floor charging includes every account, including explicit caps.
            $lifts['would_be'] += $shortfall;
        }
        unset($row);
        // Both projections keep their own charge, but use the same ordered calculation path.
        $pools = array_map(static function (float $lift) use ($pool, $settings): float { return max(0.0, $pool - $settings['floorCharge'] * $lift); }, $lifts);
        $water = array_fill_keys(array_keys($pools), []);
        if ($fallback === null && $settings['mode'] === 'enforce' && $rates !== null) {
            $demand = [];
            foreach ($rows as $user => &$row) {
                $row['rate'] = pmssFiniteNonNegativeFloat($rates[$user] ?? null);
                $explicit = $row['explicit'] ? pmssIopsParseSpec($users[$user]['source']['IOReadIOPS']) : null;
                // Unknown or held demand is unbounded; quiet readers keep headroom, within their usable cap.
                $demand[$user] = min((float) min($row['ceiling'] ?? INF, $explicit ?? INF),
                    $row['rate'] === null || ($wantsMore[$user] ?? false) === true ? INF : max((float) $row['floor'], $row['rate'] * $settings['workConservingHeadroom']));
                // JSON cannot encode INF; null denotes unbounded demand in the private cycle log.
                $row['demand'] = is_finite($demand[$user]) ? $demand[$user] : null;
            }
            unset($row);
            $weights = array_map(static function (array $row): float { return $row['share']; }, $rows);
            $water = array_map(static function (float $charged) use ($weights, $demand): array { return pmssDynamicIopsWaterFill($weights, $demand, $charged); }, $pools);
            foreach ($rows as $user => &$row) $row['water'] = array_map(static function (array $allocation) use ($user): float { return $allocation[$user]; }, $water);
            unset($row);
        }
        foreach ($rows as $user => &$row) {
            foreach ($pools as $projection => $charged) {
                $row[$projection] = $projection === 'proposed' && ($fallback !== null || $row['explicit']) ? $row['legacy'] :
                    ($fallback !== null ? null : pmssDynamicIopsProposal($row, $charged, $water[$projection][$user] ?? 0.0));
            }
        }
        unset($row);
        $result['variants'][$label] = ['alpha' => $alpha, 'floor_lift' => $lifts['proposed'], 'charged_pool' => $pools['proposed'],
            'users' => $rows, 'would_be_floor_lift' => $lifts['would_be'], 'would_be_charged_pool' => $pools['would_be']];
    }
    return $result;
}

/** Return one complete enforced read-cap map, never legacy caps on failed evidence. */
function pmssDynamicIopsReadCaps(array $users, array $policy, ?array $result): ?array
{
    $settings = pmssDynamicIopsSettings($policy);
    if ($settings === null || $settings['mode'] !== 'enforce' || $result === null
        || !array_key_exists('fallback', $result) || $result['fallback'] !== null) return null;
    $rows = $result['variants'][$settings['quotaExponent'] === 1.0 ? '1.0' : '0.5']['users'] ?? [];
    $caps = [];
    foreach ($users as $user => $entry) {
        $cap = $rows[$user]['would_be'] ?? null;
        if (!is_int($cap) || $cap <= 0) return null;
        $explicit = pmssIopsParseSpec($entry['source']['IOReadIOPS'] ?? null);
        $caps[$user] = $explicit === null ? $cap : min($explicit, $cap);
    }
    return $caps;
}
