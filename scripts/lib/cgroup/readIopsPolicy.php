<?php
/**
 * Derived read-IOPS policy for the cgroup-v1 direct enforcer.
 *
 * The value is computed fresh every apply cycle and never written back to
 * /etc/seedbox/config/users/*.json.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Return a positive integer setting or null without coercing invalid policy. */
function pmssCgroupPolicyPositiveIntSetting($value): ?int
{
    if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
        return null;
    }

    $integer = (int) $value;
    return $integer > 0 ? $integer : null;
}

/** Return true when the user payload carries an operator-set read-IOPS value. */
function pmssCgroupPolicyUserHasExplicitReadIops(array $source): bool
{
    return isset($source['IOReadIOPS']) && is_scalar($source['IOReadIOPS']);
}

/** Read the review-adjustable host oversell multiplier. */
function pmssCgroupPolicyReadIopsOversell(array $policy): float
{
    $value = $policy['cgroup']['assignMax']['iops'] ?? null;
    if ((!is_int($value) && !is_float($value) && !is_string($value))
        || !is_numeric($value)
        || !is_finite((float) $value)
        || (float) $value <= 0) {
        return 2.0;
    }

    return (float) $value;
}

/** Return read-IOPS floors keyed by storage class. */
function pmssCgroupPolicyReadIopsClassFloors(array $policy): array
{
    $floors = ['default' => 200, 'storage' => 200, 'hdd' => 200, 'ssd' => 500, 'nvme' => 750];
    $configured = $policy['cgroup']['readIopsClassFloors'] ?? [];
    if (is_array($configured)) {
        foreach ($configured as $class => $value) {
            if (!is_string($class) || $class === '') {
                continue;
            }
            $floor = pmssCgroupPolicyPositiveIntSetting($value);
            if ($floor !== null) {
                $floors[strtolower($class)] = $floor;
            }
        }
    }
    if ($floors['nvme'] < $floors['ssd']) {
        $floors['nvme'] = $floors['ssd'];
    }

    return $floors;
}

/** Resolve the class floor, falling back to the profile default when unknown. */
function pmssCgroupPolicyReadIopsClassFloor(?string $storageClass, array $policy): int
{
    $floors = pmssCgroupPolicyReadIopsClassFloors($policy);
    $class = is_string($storageClass) ? strtolower($storageClass) : '';
    return $floors[$class] ?? $floors['default'] ?? $floors['storage'] ?? 200;
}

/** Prefer explicit user IOWeight, then the policy default, then systemd's default-ish 100. */
function pmssCgroupPolicyUserIoWeight(array $source, array $policy): int
{
    foreach ([$source['IOWeight'] ?? null, $policy['ioWeight'] ?? null, 100] as $value) {
        $weight = pmssCgroupPolicyPositiveIntSetting($value);
        if ($weight !== null) {
            return $weight;
        }
    }

    return 100;
}

/** Sum IOWeight over the nominal provisioned user set. */
function pmssCgroupPolicyUserIoWeightSum(array $userPayloads, array $policy): int
{
    $sum = 0;
    foreach ($userPayloads as $payload) {
        if (is_array($payload)) {
            $sum += pmssCgroupPolicyUserIoWeight($payload, $policy);
        }
    }

    return $sum;
}

/** Resolve the tier-level absolute read-IOPS ceiling when provisioned data carries one. */
function pmssCgroupPolicyReadIopsTierCeiling(array $source, array $policy): ?int
{
    $direct = pmssCgroupPolicyPositiveIntSetting($source['resourceIOPSReadMax'] ?? null);
    if ($direct !== null) {
        return $direct;
    }

    $serviceType = $source['serviceType'] ?? null;
    if (is_array($serviceType)) {
        return pmssCgroupPolicyPositiveIntSetting($serviceType['resourceIOPSReadMax'] ?? null);
    }
    if (is_scalar($serviceType)) {
        $key = trim((string) $serviceType);
        $catalog = $policy['serviceTypes'] ?? [];
        if ($key !== '' && is_array($catalog) && is_array($catalog[$key] ?? null)) {
            return pmssCgroupPolicyPositiveIntSetting($catalog[$key]['resourceIOPSReadMax'] ?? null);
        }
    }

    return null;
}

/** Clamp the computed read-IOPS cap while honoring the absolute tier ceiling. */
function pmssCgroupPolicyReadIopsClamp(int $candidate, int $classFloor, int $tierCeiling): int
{
    $classFloor = max(1, $classFloor);
    $tierCeiling = max(1, $tierCeiling);
    if ($candidate > $tierCeiling) {
        return $tierCeiling;
    }
    if ($candidate < $classFloor) {
        return min($classFloor, $tierCeiling);
    }

    return $candidate;
}

/**
 * Validate host floor pressure before applying derived caps.
 *
 * A valid host estimate that is below the sum of per-class floors means the
 * floor itself would overcommit the measured host maximum; skip derived caps
 * and make the condition visible.
 */
function pmssCgroupPolicyReadIopsDerivationAllowed(?float $hostMaxReadIops, int $classFloor, int $nominalUserCount, ?callable $logger = null): bool
{
    if ($hostMaxReadIops === null || !is_finite($hostMaxReadIops) || $hostMaxReadIops < 0 || $nominalUserCount <= 0) {
        return true;
    }

    $floorSum = max(1, $classFloor) * $nominalUserCount;
    if ($floorSum <= $hostMaxReadIops) {
        return true;
    }

    if ($logger !== null) {
        $logger('read-iops derivation skipped: class floor sum '.$floorSum.' exceeds host max '.(int) floor($hostMaxReadIops));
    }
    return false;
}

/** Derive one user's transient read-IOPS cap, or null when explicit/disabled. */
function pmssCgroupPolicyDerivedReadIopsCap(
    array $source,
    array $policy,
    ?float $hostMaxReadIops,
    int $weightSum,
    int $nominalUserCount,
    ?string $storageClass,
    bool $derivationAllowed = true
): ?int {
    if (pmssCgroupPolicyUserHasExplicitReadIops($source) || !$derivationAllowed || $nominalUserCount <= 0) {
        return null;
    }

    $floor = pmssCgroupPolicyReadIopsClassFloor($storageClass, $policy);
    $tierCeiling = pmssCgroupPolicyReadIopsTierCeiling($source, $policy);
    if ($hostMaxReadIops === null || $weightSum <= 0 || $tierCeiling === null) {
        return $tierCeiling === null ? $floor : pmssCgroupPolicyReadIopsClamp($floor, $floor, $tierCeiling);
    }

    $share = pmssCgroupPolicyUserIoWeight($source, $policy) / $weightSum;
    $candidate = (int) floor(max(0.0, $hostMaxReadIops) * pmssCgroupPolicyReadIopsOversell($policy) * $share);
    return pmssCgroupPolicyReadIopsClamp($candidate, $floor, $tierCeiling);
}

/** Extract a safe block device name from a /dev path. */
function pmssCgroupPolicyBlockDeviceName(string $devicePath): ?string
{
    $device = basename(trim($devicePath));
    if ($device === '' || preg_match('/^[A-Za-z0-9._!+-]+\z/', $device) !== 1) {
        return null;
    }

    return $device;
}

/** Return true when the /home source is an md device; derived caps wait for #623. */
function pmssCgroupPolicyHomeDeviceIsMdBacked(string $devicePath, string $sysClassBlock = '/sys/class/block'): bool
{
    $device = pmssCgroupPolicyBlockDeviceName($devicePath);
    if ($device === null) {
        return false;
    }

    return preg_match('/^md\d+\z/', $device) === 1
        || is_dir(rtrim($sysClassBlock, '/').'/'.$device.'/md');
}

/** Resolve one block device's storage class from sysfs rotational/NVMe primitives. */
function pmssCgroupPolicyBlockStorageClassResolve(string $deviceName, string $sysClassBlock, array &$seen): ?string
{
    $device = pmssCgroupPolicyBlockDeviceName($deviceName);
    if ($device === null || isset($seen[$device])) {
        return null;
    }
    $seen[$device] = true;

    if (preg_match('/^nvme\d+n\d+(?:p\d+)?\z/', $device) === 1) {
        return 'nvme';
    }

    $root = rtrim($sysClassBlock, '/');
    $class = null;
    $rotational = @file_get_contents($root.'/'.$device.'/queue/rotational');
    if (is_string($rotational)) {
        $trimmed = trim($rotational);
        if ($trimmed === '1') {
            return 'hdd';
        }
        if ($trimmed === '0') {
            $class = 'ssd';
        }
    }

    $slaveClasses = [];
    foreach (glob($root.'/'.$device.'/slaves/*') ?: [] as $slavePath) {
        $slaveClass = pmssCgroupPolicyBlockStorageClassResolve((string) basename($slavePath), $sysClassBlock, $seen);
        if ($slaveClass !== null) {
            $slaveClasses[] = $slaveClass;
        }
    }
    if (in_array('hdd', $slaveClasses, true)) {
        return 'hdd';
    }
    if (in_array('nvme', $slaveClasses, true)) {
        return 'nvme';
    }
    if (in_array('ssd', $slaveClasses, true)) {
        return 'ssd';
    }

    return $class;
}

/** Detect the class of the single-device /home backing block device. */
function pmssCgroupPolicyHomeStorageClassResolve(string $devicePath, string $sysClassBlock = '/sys/class/block'): ?string
{
    $device = pmssCgroupPolicyBlockDeviceName($devicePath);
    if ($device === null) {
        return null;
    }

    $seen = [];
    return pmssCgroupPolicyBlockStorageClassResolve($device, $sysClassBlock, $seen);
}
