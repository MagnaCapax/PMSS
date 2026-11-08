<?php
/** Pure quota variants projected from one account cohort. Loaded by dynamicIopsPolicy.php. @license GPL-3.0-only */
require_once __DIR__.'/directApply.php';

/** Keep identical floor, two-significant-figure rounding and ceiling for both projections. */
function pmssDynamicIopsProposal(array $row, float $chargedPool): int
{
    $value = max($row['floor'], $chargedPool * $row['share']);
    $rounded = max($row['floor'], round($value, 1 - (int) floor(log10(max(1.0, $value)))));
    // The kernel throttle delivers whole tens per second, so round up to the next ten it delivers.
    return min($row['ceiling'] ?? PHP_INT_MAX, (int) min(ceil($rounded / 10) * 10, PHP_INT_MAX - 1024));
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
    // Only solid state uses a latency band (ADR 0237); HDDs use paired work blocks (ADR 0238).
    $latency = pmssFiniteNonNegativeFloat($pressure['latency_ms'] ?? null);
    $media = $pressure['media'] ?? null;
    $reference = pmssFiniteNonNegativeFloat($pressure['latency_reference_ms'] ?? null);
    $band = in_array($media, ['ssd', 'nvme'], true) && $reference !== null && $reference > 0
        ? [$settings['ssdLatencyLowX'] * $reference, $settings['ssdLatencyHighX'] * $reference] : null;
    $steer = $latency !== null && $band !== null ? 'latency' : 'pressure';
    if ($steer === 'latency') {
        $previous = pmssFiniteNonNegativeFloat($pressure['previous_multiplier'] ?? null) ?? $multiplier;
        $next = $latency < $band[0] ? $previous * $settings['latencyStep']
            : ($latency > $band[1] ? $previous / $settings['latencyStep'] : $previous);
        $multiplier = max($settings['busyMultiplier'], min($settings['idleMultiplier'], $next));
    }
    if ($media === 'hdd') {
        $steer = 'probe';
        $multiplier = $pressure['probe_multiplier'] ?? $settings['probeStartLo'];
    }
    $passive = pmssFiniteNonNegativeFloat($passive);
    $benchmark = pmssFiniteNonNegativeFloat($benchmark);
    // A cached fio figure can exceed real disk IOPS a thousandfold; a cap needs the measured ceiling.
    $host = $passive ?? 0.0;
    $pool = $host * $multiplier;
    if (!is_finite($pool) || $pool >= PHP_INT_MAX) return null;
    $iowait = pmssFiniteNonNegativeFloat($pressure['iowait_blend'] ?? null);
    if ($iowait !== null && $iowait > 100) $iowait = null;
    $curb = $iowait === null ? 1.0 : 1 - $settings['curbMaxCut'] * max(0.0, min(1.0,
        ($iowait - $settings['curbStartIowait']) / ($settings['curbFullIowait'] - $settings['curbStartIowait'])));
    $uncurbed = $pool;
    $pool *= $curb;
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
    // Incomplete cohort data must not redistribute other accounts' shares. The probe's multiplier does
    // not come from the blend, so a late iostat sample cannot stop it (ADR 0238).
    $fallback = $host <= 0 ? 'missing-ceiling' : ($blend === null && $steer !== 'probe' ? 'missing-pressure' :
        ($count !== count($users) ? 'missing-quota-or-weight' : null));
    $low = $count === 0 ? 1.0 : $sorted[intdiv($count - 1, 2)];
    $median = $count === 0 ? 1.0 : $low + ($sorted[intdiv($count, 2)] - $low) / 2;
    $result = ['pressure' => $pressure, 'passive_read_iops' => $passive, 'benchmark_read_iops' => $benchmark,
        'host_read_iops' => $host, 'multiplier' => $multiplier, 'steer' => $steer, 'pool' => $pool, 'fallback' => $fallback, 'variants' => []];
    foreach (['1.0' => 1.0, '0.5' => 0.5] as $label => $alpha) {
        $rows = $cohort; $lift = 0.0; $wouldLift = 0.0;
        foreach ($rows as $user => $row) {
            $rows[$user]['share'] = (pmssFiniteNonNegativeFloat($row['weight']) ?? 0) * pow(($row['quota'] ?? $median) / $median, $alpha);
        }
        $sum = array_sum(array_column($rows, 'share'));
        if (!is_finite($sum)) return null;
        foreach ($rows as &$row) {
            $row['share'] = $sum > 0 ? $row['share'] / $sum : 0.0;
            $shortfall = max(0.0, $row['floor'] - $pool * $row['share']);
            if (!$row['explicit']) $lift += $shortfall;
            // Counterfactual floor charging includes every account, including explicit caps.
            $wouldLift += $shortfall;
        }
        unset($row);
        $chargedPool = max(0.0, $pool - $settings['floorCharge'] * $lift);
        $wouldPool = max(0.0, $pool - $settings['floorCharge'] * $wouldLift);
        foreach ($rows as &$row) {
            $row['proposed'] = $fallback !== null || $row['explicit'] ? $row['legacy'] :
                pmssDynamicIopsProposal($row, $chargedPool);
            $row['would_be'] = $fallback !== null ? null : pmssDynamicIopsProposal($row, $wouldPool);
        }
        unset($row);
        $result['variants'][$label] = ['alpha' => $alpha, 'floor_lift' => $lift, 'charged_pool' => $chargedPool,
            'users' => $rows, 'would_be_floor_lift' => $wouldLift, 'would_be_charged_pool' => $wouldPool];
    }
    $result += ['iowait_blend' => $iowait, 'curb_factor' => $curb, 'pool_uncurbed' => $uncurbed];
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
