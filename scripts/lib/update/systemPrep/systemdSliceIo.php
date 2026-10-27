<?php
/** Mount policy rendering for shared user-slice templates. @license GPL-3.0-only */

/** Render untrusted policy/findmnt fragments safely in single-line logs. */
function pmssSystemdLogValue($value): string
{
    $safe = str_replace(["\r", "\n", "\0"], ['\\r', '\\n', '\\0'], (string) $value);
    return strlen($safe) > 160 ? substr($safe, 0, 157).'...' : $safe;
}

/** Keep the existing optional latency directive and v1 skip diagnostic. */
function pmssSystemdSliceIoLatency(array $policy, string $mode, callable $log): string
{
    if (!is_numeric($policy['ioLatencyMs'] ?? null) || (int) $policy['ioLatencyMs'] <= 0) return '';
    if ($mode !== 'v2') {
        $log('[SKIP] IODeviceLatencyTargetSec skipped on cgroup v1');
        return '';
    }
    $homeDevice = pmssCgroupPolicyMountSourceResolve('/home');
    if ($homeDevice !== '' && pmssCgroupPolicyDeviceTargetIsSafe($homeDevice)) {
        return 'IODeviceLatencyTargetSec='.$homeDevice.' '.(int) $policy['ioLatencyMs'].'ms';
    }
    $log($homeDevice !== ''
        ? '[WARN] Unsafe /home backing device for IODeviceLatencyTargetSec: '.pmssSystemdLogValue($homeDevice)
        : '[WARN] Unable to resolve /home backing device for IODeviceLatencyTargetSec');
    return '';
}

/** Append ordered per-mount limits through the existing device/value validators. */
function pmssSystemdSliceIoMounts(array $policy, string $mode, callable $log): string
{
    if (!is_array($policy['mounts'] ?? null)) return '';
    $append = [];
    $skippedDeviceWeights = false;
    foreach ($policy['mounts'] as $mount => $def) {
        if (!is_array($def) || ($src = pmssCgroupPolicyMountSourceResolve((string) $mount)) === '') continue;
        if (!pmssCgroupPolicyDeviceTargetIsSafe($src)) {
            $log('[WARN] Unsafe backing device for cgroup policy mount '.pmssSystemdLogValue($mount).': '.pmssSystemdLogValue($src));
            continue;
        }
        if (is_numeric($def['ioWeight'] ?? null)) $skippedDeviceWeights = $skippedDeviceWeights || $mode !== 'v2';
        $unsafeKeys = [];
        $append = array_merge($append, pmssCgroupPolicyIoPairs($def, $src, $mode === 'v2', true, $unsafeKeys));
        foreach ($unsafeKeys as $policyKey) {
            $log('[WARN] Unsafe '.$policyKey.' value for cgroup policy mount '.pmssSystemdLogValue($mount).', skipping');
        }
    }
    if ($skippedDeviceWeights) $log('[SKIP] Per-device IO weights skipped on cgroup v1 (blkio.weight_device unsupported)');
    return $append === [] ? '' : "\n".implode("\n", $append)."\n";
}
