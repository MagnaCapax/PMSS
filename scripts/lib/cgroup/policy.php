<?php
/**
 * Shared cgroup policy helpers for CLI and systemd slice rendering.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/pathSafety.php';

const PMSS_BFQ_KERNEL_MAX = 1000;
const PMSS_BFQ_FALLBACK_MAX_BONUS_PERCENT = 300;
const PMSS_BFQ_FALLBACK_BASE_MAX = 250;
const PMSS_BFQ_FALLBACK_REFERENCE_MEMORY_MIB = 131072; // 128 GiB.
const PMSS_BFQ_FALLBACK_COEFFICIENT = 0.690533966002; // 250 / sqrt(131072).

/** Load the PMSS cgroup policy array from the configured seedbox config tree. */
function pmssCgroupPolicyLoad(?string $configDir = null): array
{
    $cfgDir = rtrim($configDir ?? pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config'), '/');
    $policyFile = ($cfgDir !== '' ? $cfgDir : '/etc/seedbox/config').'/cgroup.policy.php';
    $loaded = file_exists($policyFile) ? @include $policyFile : null;

    return is_array($loaded) ? $loaded : [];
}

/** Keep systemd IO property device targets to plain block-device paths. */
function pmssCgroupPolicyDeviceTargetIsSafe(string $device): bool { return pmssPathAbsoluteStringIsSafe($device, ['allowTrailingSlash' => false, 'allowWhitespace' => false, 'requiredPrefix' => '/dev/']); }

/** Map IO profile default policy keys to userConfigCgroup option keys. */
function pmssCgroupPolicyIoDefaultMap(): array { return ['ioWeight' => 'io-weight', 'cpuWeight' => 'cpu-weight', 'tasksMax' => 'tasks-max']; }

/** Map policy IO limit keys to systemd property names and value validation mode. */
function pmssCgroupPolicyIoPairSpecs(bool $includeWeight = true): array
{
    $limits = ['readBw' => ['IOReadBandwidthMax', false], 'writeBw' => ['IOWriteBandwidthMax', false], 'readIops' => ['IOReadIOPSMax', true], 'writeIops' => ['IOWriteIOPSMax', true]];
    return $includeWeight ? ['ioWeight' => ['IODeviceWeight', true]] + $limits : $limits;
}

/** Read one positive policy value, preserving skip-on-invalid behavior. */
function pmssCgroupPolicyPositiveValue(array $source, string $key, bool $numeric): ?string
{
    if (!isset($source[$key])) return null;
    if (!is_scalar($source[$key])) return null;
    if ($numeric) return is_numeric($source[$key]) && (int) $source[$key] > 0 ? (string) (int) $source[$key] : null;
    $value = trim((string) $source[$key]);
    return $value === '' ? null : $value;
}

/**
 * Derive a bonus-headroom bfq.weight base from a memory baseline.
 *
 * The default curve maps 128 GiB to 250 so the documented 300% bonus
 * multiplier can reach, but not exceed, the kernel bfq.weight cap of 1000.
 * This is the cgroup-v1 direct BFQ kernel fallback curve; systemd slice
 * MemoryHigh-derived CPU defaults remain separate.
 */
function pmssBfqFormulaWeight(
    int $memoryMiB,
    float $coefficient = PMSS_BFQ_FALLBACK_COEFFICIENT,
    int $customerMax = PMSS_BFQ_FALLBACK_BASE_MAX
): int
{
    if ($memoryMiB <= 0) {
        return 1;
    }
    $customerMax = max(1, $customerMax);
    $derived = (int) round($coefficient * sqrt($memoryMiB));
    return max(1, min($customerMax, $derived));
}

/**
 * Apply a non-negative BFQ bonus percent and clamp to the kernel range.
 */
function pmssBfqApplyBonusWeight(int $baseWeight, int $bonusPct, int $kernelMax = PMSS_BFQ_KERNEL_MAX): int
{
    $kernelMax = max(1, $kernelMax);
    $bonusPct = max(0, $bonusPct);
    $weighted = (int) round($baseWeight * (1 + $bonusPct / 100));
    return max(1, min($kernelMax, $weighted));
}

/** Parse one kernel bfq.weight sysfs payload without silently coercing errors. */
function pmssBfqKernelWeightParse($raw): ?int
{
    if (!is_string($raw)) {
        return null;
    }

    $value = trim($raw);
    if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
        return null;
    }

    $weight = (int) $value;
    return $weight >= 1 && $weight <= PMSS_BFQ_KERNEL_MAX ? $weight : null;
}

/** Resolve a safe non-root UID from a passwd entry before sysfs path assembly. */
function pmssBfqUserPasswdUid($passwdEntry): ?int
{
    if (!is_array($passwdEntry) || !array_key_exists('uid', $passwdEntry)) {
        return null;
    }

    $uid = $passwdEntry['uid'];
    if (is_int($uid)) {
        return $uid > 0 ? $uid : null;
    }

    if (!is_string($uid) || preg_match('/^[1-9][0-9]*$/', $uid) !== 1) {
        return null;
    }

    $parsed = filter_var($uid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return is_int($parsed) ? $parsed : null;
}

/** Resolve one numeric profile value from policy overrides plus built-in defaults. */
function pmssCgroupPolicyNumericProfileValue(array $policy, string $family, string $profileName, array $defaults, string $fallback): string
{
    $profileName = strtolower($profileName);
    $profiles = isset($policy['profiles'][$family]) && is_array($policy['profiles'][$family]) ? $policy['profiles'][$family] : [];
    foreach ($profiles as $name => $value) {
        if (!is_string($name) || $name === '' || !is_numeric($value) || (int) $value <= 0) {
            continue;
        }
        $defaults[strtolower($name)] = (string) (int) $value;
    }

    return $defaults[$profileName] ?? $fallback;
}

/** Merge policy-defined IO profiles into the built-in shorthand profiles. */
function pmssCgroupPolicyIoProfiles(array $policy, array $builtIns): array
{
    if (!isset($policy['profiles']['io']) || !is_array($policy['profiles']['io'])) {
        return $builtIns;
    }

    foreach ($policy['profiles']['io'] as $name => $config) {
        if (!is_string($name) || $name === '' || !is_array($config)) {
            continue;
        }

        $key = strtolower($name);
        $entry = isset($builtIns[$key]) && is_array($builtIns[$key]) ? $builtIns[$key] : ['defaults' => [], 'limits' => []];
        $changed = false;

        foreach (pmssCgroupPolicyIoDefaultMap() as $policyKey => $targetKey) {
            $value = pmssCgroupPolicyPositiveValue($config, $policyKey, true);
            if ($value === null) continue;
            $entry['defaults'][$targetKey] = $value;
            $changed = true;
        }
        foreach (pmssCgroupPolicyIoPairSpecs(false) as $limitKey => $mapping) {
            $value = pmssCgroupPolicyPositiveValue($config, $limitKey, (bool) $mapping[1]);
            if ($value === null) continue;
            $entry['limits'][$limitKey] = $value;
            $changed = true;
        }
        if ($changed) $builtIns[$key] = $entry;
    }

    return $builtIns;
}

/**
 * Render IO policy pairs for one resolved device.
 */
function pmssCgroupPolicyIoPairs(array $source, string $devicePath, bool $includeWeight = true, bool $rejectLimitWhitespace = false, ?array &$skippedUnsafeKeys = null): array
{
    $pairs = [];
    $skippedUnsafeKeys = [];

    foreach (pmssCgroupPolicyIoPairSpecs($includeWeight) as $policyKey => $mapping) {
        $value = pmssCgroupPolicyPositiveValue($source, $policyKey, (bool) $mapping[1]);
        if ($value === null) continue;
        if ($rejectLimitWhitespace && !$mapping[1] && preg_match('/\s/', $value) === 1) {
            $skippedUnsafeKeys[] = $policyKey;
            continue;
        }

        $pairs[] = $mapping[0].'='.$devicePath.' '.$value;
    }
    return $pairs;
}
