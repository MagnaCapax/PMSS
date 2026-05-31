<?php
/**
 * Shared cgroup policy helpers for CLI and systemd slice rendering.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/pathSafety.php';

/** Load the PMSS cgroup policy array from the configured seedbox config tree. */
function pmssCgroupPolicyLoad(?string $configDir = null): array
{
    if ($configDir === null) {
        $envDir = getenv('PMSS_CONFIG_DIR');
        $configDir = ($envDir === false || $envDir === '') ? '/etc/seedbox/config' : $envDir;
    }
    $cfgDir = rtrim($configDir, '/');
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

/** Derive a customer-tier-clamped bfq.weight value from a memory baseline. */
function pmssBfqFormulaWeight(int $memoryMiB, float $coefficient = 3.535, int $customerMax = 700): int
{
    if ($memoryMiB <= 0) {
        return 1;
    }
    $customerMax = max(1, $customerMax);
    $derived = (int) round($coefficient * sqrt($memoryMiB));
    return max(1, min($customerMax, $derived));
}

/** Resolve one numeric profile value from policy overrides plus built-in defaults. */
function pmssCgroupPolicyNumericProfileValue(array $policy, string $family, string $profileName, array $defaults, string $fallback): string
{
    $profileName = strtolower($profileName);
    if (!isset($policy['profiles']) || !is_array($policy['profiles']) || !isset($policy['profiles'][$family]) || !is_array($policy['profiles'][$family])) {
        return $defaults[$profileName] ?? $fallback;
    }

    foreach ($policy['profiles'][$family] as $name => $value) {
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
