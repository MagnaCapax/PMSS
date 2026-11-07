<?php
/**
 * Validated read-IOPS allocation policy.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/policy.php';

/** Enforce by default; reject malformed knobs rather than silently changing the allocation. */
function pmssDynamicIopsSettings(array $policy): ?array
{
    $settings = $policy['cgroup']['dynamicIops'] ?? [];
    if (!is_array($settings)) return null;
    $settings += ['mode' => 'enforce', 'idlePressure' => 5.0, 'busyPressure' => 15.0,
        'idleMultiplier' => 10.0, 'busyMultiplier' => 2.0, 'floor' => 15, 'floorCharge' => 0.25,
        'curbStartIowait' => 50.0, 'curbFullIowait' => 80.0, 'curbMaxCut' => 0.25, 'quotaExponent' => 1.0,
        'latencyLowMs' => 20.0, 'latencyHighMs' => 25.0, 'latencyStep' => 1.1,
        'ssdLatencyLowX' => 5.0, 'ssdLatencyHighX' => 10.0];
    // Shadow mode was removed; a host still set to it enforces.
    if ($settings['mode'] === 'shadow') $settings['mode'] = 'enforce';
    if (!in_array($settings['mode'], ['off', 'enforce'], true)) return null;
    foreach (['idlePressure', 'busyPressure', 'idleMultiplier', 'busyMultiplier', 'floor', 'floorCharge',
        'curbStartIowait', 'curbFullIowait', 'curbMaxCut', 'quotaExponent', 'latencyLowMs', 'latencyHighMs',
        'latencyStep', 'ssdLatencyLowX', 'ssdLatencyHighX'] as $key) {
        $settings[$key] = pmssFiniteNonNegativeFloat($settings[$key]);
        if ($settings[$key] === null) return null;
    }
    return $settings['idlePressure'] < $settings['busyPressure'] && $settings['busyPressure'] <= 100
        && $settings['busyMultiplier'] >= 2 && $settings['idleMultiplier'] >= $settings['busyMultiplier']
        && $settings['idleMultiplier'] <= 100 && $settings['floor'] >= 1 && $settings['floor'] <= 1000000
        && $settings['floorCharge'] <= 1 && $settings['curbStartIowait'] < $settings['curbFullIowait']
        && $settings['curbFullIowait'] <= 100 && $settings['curbMaxCut'] <= 1
        && $settings['latencyLowMs'] < $settings['latencyHighMs'] && $settings['latencyHighMs'] <= 1000
        && $settings['latencyStep'] > 1 && $settings['latencyStep'] <= 2
        && $settings['ssdLatencyLowX'] >= 1 && $settings['ssdLatencyLowX'] < $settings['ssdLatencyHighX']
        && $settings['ssdLatencyHighX'] <= 1000
        && in_array($settings['quotaExponent'], [1.0, 0.5], true) ? $settings : null;
}

require_once __DIR__.'/dynamicIopsPressure.php';
require_once __DIR__.'/dynamicIopsAllocation.php';
