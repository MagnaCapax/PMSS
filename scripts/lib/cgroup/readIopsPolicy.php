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

require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/readIopsStorage.php';

/** Return a positive integer setting or null without coercing invalid policy. */
function pmssCgroupPolicyPositiveIntSetting($value): ?int
{
    if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
        return null;
    }

    $integer = (int) $value;
    return $integer > 0 ? $integer : null;
}

/** Return read-IOPS floors keyed by storage class. */
function pmssCgroupPolicyReadIopsClassFloors(array $policy): array
{
    $floors = ['default' => 200, 'storage' => 200, 'hdd' => 200, 'ssd' => 500, 'nvme' => 750];
    $configured = $policy['cgroup']['readIopsClassFloors'] ?? [];
    foreach (is_array($configured) ? $configured : [] as $class => $value) {
        if (is_string($class) && $class !== '' && ($floor = pmssCgroupPolicyPositiveIntSetting($value)) !== null) {
            $floors[strtolower($class)] = $floor;
        }
    }
    $floors['nvme'] = max($floors['nvme'], $floors['ssd']);

    return $floors;
}

/** Resolve the class floor, falling back to the profile default when unknown. */
function pmssCgroupPolicyReadIopsClassFloor(?string $storageClass, array $policy): int
{
    $floors = pmssCgroupPolicyReadIopsClassFloors($policy);
    $class = $storageClass !== null ? strtolower($storageClass) : '';
    return $floors[$class] ?? $floors['default'];
}

/** Prefer explicit user IOWeight, then the policy default, then systemd's default-ish 100. */
function pmssCgroupPolicyUserIoWeight(array $source, array $policy): int
{
    return pmssCgroupPolicyPositiveIntSetting($source['IOWeight'] ?? null)
        ?? pmssCgroupPolicyPositiveIntSetting($policy['ioWeight'] ?? null) ?? 100;
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
    if (!is_array($serviceType)) {
        $key = is_scalar($serviceType) ? trim((string) $serviceType) : '';
        $catalog = $policy['serviceTypes'] ?? [];
        $serviceType = $key !== '' && is_array($catalog) ? ($catalog[$key] ?? null) : null;
    }
    return is_array($serviceType) ? pmssCgroupPolicyPositiveIntSetting($serviceType['resourceIOPSReadMax'] ?? null) : null;
}

/** Clamp the computed read-IOPS cap while honoring the absolute tier ceiling. */
function pmssCgroupPolicyReadIopsClamp(int $candidate, int $classFloor, int $tierCeiling): int
{
    $classFloor = max(1, $classFloor);
    $tierCeiling = max(1, $tierCeiling);
    return min($tierCeiling, max($classFloor, $candidate));
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
    if ((isset($source['IOReadIOPS']) && is_scalar($source['IOReadIOPS'])) || !$derivationAllowed || $nominalUserCount <= 0) {
        return null;
    }

    $floor = pmssCgroupPolicyReadIopsClassFloor($storageClass, $policy);
    $tierCeiling = pmssCgroupPolicyReadIopsTierCeiling($source, $policy);
    if ($hostMaxReadIops === null || $weightSum <= 0 || $tierCeiling === null) {
        return $tierCeiling === null ? $floor : pmssCgroupPolicyReadIopsClamp($floor, $floor, $tierCeiling);
    }

    $share = pmssCgroupPolicyUserIoWeight($source, $policy) / $weightSum;
    $oversell = pmssFiniteNonNegativeFloat($policy['cgroup']['assignMax']['iops'] ?? null);
    $candidate = (int) floor(max(0.0, $hostMaxReadIops) * ($oversell !== null && $oversell > 0 ? $oversell : 2.0) * $share);
    return pmssCgroupPolicyReadIopsClamp($candidate, $floor, $tierCeiling);
}
