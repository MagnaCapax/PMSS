<?php
/**
 * Validated opt-in read-IOPS allocation policy.
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
        'idleMultiplier' => 10.0, 'busyMultiplier' => 2.0, 'floor' => 15, 'floorCharge' => 0.25,
        'curbStartIowait' => 50.0, 'curbFullIowait' => 80.0, 'curbMaxCut' => 0.25, 'quotaExponent' => 1.0];
    if (!in_array($settings['mode'], ['off', 'shadow', 'enforce'], true)) return null;
    foreach (['idlePressure', 'busyPressure', 'idleMultiplier', 'busyMultiplier', 'floor', 'floorCharge',
        'curbStartIowait', 'curbFullIowait', 'curbMaxCut', 'quotaExponent'] as $key) {
        $settings[$key] = pmssFiniteNonNegativeFloat($settings[$key]);
        if ($settings[$key] === null) return null;
    }
    return $settings['idlePressure'] < $settings['busyPressure'] && $settings['busyPressure'] <= 100
        && $settings['busyMultiplier'] >= 2 && $settings['idleMultiplier'] >= $settings['busyMultiplier']
        && $settings['idleMultiplier'] <= 100 && $settings['floor'] >= 1 && $settings['floor'] <= 1000000
        && $settings['floorCharge'] <= 1 && $settings['curbStartIowait'] < $settings['curbFullIowait']
        && $settings['curbFullIowait'] <= 100 && $settings['curbMaxCut'] <= 1
        && in_array($settings['quotaExponent'], [1.0, 0.5], true) ? $settings : null;
}

require_once __DIR__.'/dynamicIopsPressure.php';
require_once __DIR__.'/dynamicIopsAllocation.php';
