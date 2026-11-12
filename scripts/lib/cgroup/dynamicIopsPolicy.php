<?php
/**
 * Validated read-IOPS allocation policy.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/policy.php';
require_once __DIR__.'/dynamicIopsContention.php';

/**
 * Linux 6.12+ dispatches REQ_META/REQ_SWAP bios ahead of blk-throttle (commit 29390bb5661d); 6.1 queues
 * them behind the cgroup's own data reads and stalls the ext4 journal (ADR 0243). Caps also need the
 * cgroup-v1 memory and blkio controllers, which stock Debian 6.12+ builds lack (no CONFIG_MEMCG_V1).
 */
function pmssDynamicIopsKernelReady($release = null, $cgroupRoot = null): bool
{
    $release = $release === null ? (string) php_uname('r') : (string) $release;
    $cgroupRoot = $cgroupRoot === null ? '/sys/fs/cgroup' : rtrim((string) $cgroupRoot, '/');
    if (!preg_match('/^([0-9]+)\.([0-9]+)/', $release, $m)) return false;
    if ((int) $m[1] < 6 || ((int) $m[1] === 6 && (int) $m[2] < 12)) return false;
    return is_file($cgroupRoot.'/memory/memory.limit_in_bytes')
        && is_file($cgroupRoot.'/blkio/blkio.throttle.read_iops_device');
}

/** Enforce by default; reject malformed knobs rather than silently changing the allocation. */
function pmssDynamicIopsSettings(array $policy): ?array
{
    $settings = $policy['cgroup']['dynamicIops'] ?? [];
    if (!is_array($settings)) return null;
    $settings += ['mode' => 'enforce', 'idlePressure' => 5.0, 'busyPressure' => 15.0,
        'idleMultiplier' => 10.0, 'busyMultiplier' => 2.0, 'floor' => 15, 'floorCharge' => 0.25,
        'curbStartIowait' => 50.0, 'curbFullIowait' => 80.0, 'curbMaxCut' => 0.25, 'quotaExponent' => 1.0,
        'probeStartLo' => 5.0, 'probeBlockCycles' => 12, 'probePairs' => 11, 'probeGain' => 0.10,
        'latencyStep' => 1.1, 'workConservingHeadroom' => 3.0,
        'ssdLatencyLowX' => 5.0, 'ssdLatencyHighX' => 10.0];
    // Shadow mode was removed; a host still set to it enforces.
    if ($settings['mode'] === 'shadow') $settings['mode'] = 'enforce';
    // 'auto' enforces only on a kernel that keeps metadata reads out of the cap queue (ADR 0271).
    if ($settings['mode'] === 'auto') $settings['mode'] = pmssDynamicIopsKernelReady() ? 'enforce' : 'off';
    if (!in_array($settings['mode'], ['off', 'enforce'], true)) return null;
    foreach (['idlePressure', 'busyPressure', 'idleMultiplier', 'busyMultiplier', 'floor', 'floorCharge',
        'curbStartIowait', 'curbFullIowait', 'curbMaxCut', 'quotaExponent', 'probeStartLo', 'probeGain',
        'latencyStep', 'ssdLatencyLowX', 'ssdLatencyHighX', 'workConservingHeadroom'] as $key) {
        $settings[$key] = pmssFiniteNonNegativeFloat($settings[$key]);
        if ($settings[$key] === null) return null;
    }
    foreach (['probeBlockCycles' => [4, 48], 'probePairs' => [2, 24]] as $key => $range) {
        if (!is_int($settings[$key]) || $settings[$key] < $range[0] || $settings[$key] > $range[1]) return null;
    }
    return $settings['idlePressure'] < $settings['busyPressure'] && $settings['busyPressure'] <= 100
        && $settings['busyMultiplier'] >= 2 && $settings['idleMultiplier'] >= $settings['busyMultiplier']
        && $settings['idleMultiplier'] <= 100 && $settings['floor'] >= 1 && $settings['floor'] <= 1000000
        && $settings['floorCharge'] <= 1 && $settings['curbStartIowait'] < $settings['curbFullIowait']
        && $settings['curbFullIowait'] <= 100 && $settings['curbMaxCut'] <= 1
        && $settings['probeStartLo'] >= $settings['busyMultiplier']
        && $settings['probeStartLo'] <= $settings['idleMultiplier'] / 2
        && $settings['probeGain'] >= 0.01 && $settings['probeGain'] <= 1
        && $settings['latencyStep'] > 1 && $settings['latencyStep'] <= 2
        && $settings['ssdLatencyLowX'] >= 1 && $settings['ssdLatencyLowX'] < $settings['ssdLatencyHighX']
        && $settings['ssdLatencyHighX'] <= 1000
        // Stay below the wants-more threshold at steady demand, avoiding a two-cycle cap oscillation.
        && $settings['workConservingHeadroom'] <= 100
        && $settings['workConservingHeadroom'] * PMSS_DYNAMIC_IOPS_EXCLUDE_RATIO > 1
        && in_array($settings['quotaExponent'], [1.0, 0.5], true) ? $settings : null;
}

require_once __DIR__.'/dynamicIopsPressure.php';
require_once __DIR__.'/dynamicIopsAllocation.php';
