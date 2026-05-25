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
