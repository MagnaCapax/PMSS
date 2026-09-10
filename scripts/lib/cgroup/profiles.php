<?php
/**
 * Apply cgroup CLI profiles using the same flat fields as the policy file.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

namespace PMSS\Cgroup;

const PMSS_CGROUP_POLICY_OPTIONS = ['cpu-weight' => 'cpuWeight', 'io-weight' => 'ioWeight', 'tasks-max' => 'tasksMax', 'cpu-quota-percent' => 'cpuQuotaPercent', 'io-latency-ms' => 'ioLatencyMs', 'memory-high' => 'memoryHighMiB', 'memory-max' => 'memoryMaxMiB'];
const PMSS_CGROUP_NUMERIC_PROFILES = [
    'cpu-profile' => ['cpu', 'cpu-weight', '100', ['low' => '50', 'high' => '300']],
    'tasks-profile' => ['tasks', 'tasks-max', '4096', ['low' => '1024', 'high' => '8192']],
    'mem-profile' => ['mem', 'memory-high', '500', ['low' => '250', 'heavy' => '1024']],
];

/** Expand numeric shorthands after defaults, preserving explicit option precedence. */
function pmssCgroupCliExpandProfiles(array &$opt): void
{
    $policy = \pmssCgroupPolicyLoad();
    foreach (PMSS_CGROUP_NUMERIC_PROFILES as $profileKey => [$family, $target, $fallback, $profiles]) {
        if (!isset($opt[$profileKey])) continue;
        $opt[$profileKey] = strtolower($opt[$profileKey]);
        if (isset($opt[$target])) continue;
        foreach (is_array($policy['profiles'][$family] ?? null) ? $policy['profiles'][$family] : [] as $name => $value) {
            if (!is_string($name) || $name === '' || !is_numeric($value) || (int) $value <= 0) continue;
            $profiles[strtolower($name)] = (string) (int) $value;
        }
        $opt[$target] = $profiles[$opt[$profileKey]] ?? $fallback;
    }
}

/** Apply one IO profile directly; no translated defaults/limits catalog is retained. */
function pmssCgroupCliApplyIoProfile(string $profile, string $dev, array &$opt, array &$pairs): void
{
    $builtIns = [
        'hdd' => ['ioWeight' => '200', 'readBw' => '5M', 'writeBw' => '10M', 'readIops' => 100, 'writeIops' => 100],
        'nvme' => ['ioWeight' => '200'],
        'bulk' => ['ioWeight' => '500', 'cpuWeight' => '300', 'tasksMax' => '8192'],
    ];
    $defaults = ['ioWeight' => 'io-weight', 'cpuWeight' => 'cpu-weight', 'tasksMax' => 'tasks-max'];
    $entry = $builtIns[$profile] ?? [];
    $policy = \pmssCgroupPolicyLoad();
    // Keep case-insensitive overrides in source order, and ignore invalid fields.
    foreach (is_array($policy['profiles']['io'] ?? null) ? $policy['profiles']['io'] : [] as $name => $config) {
        if (!is_string($name) || $name === '' || !is_array($config) || strtolower($name) !== $profile) continue;
        foreach (array_fill_keys(array_keys($defaults), ['', true]) + \pmssCgroupPolicyIoPairSpecs(false) as $key => $spec) {
            $value = \pmssCgroupPolicyPositiveValue($config, $key, (bool) $spec[1]);
            if ($value !== null) $entry[$key] = $value;
        }
    }
    foreach ($defaults as $key => $option) {
        if (isset($entry[$key]) && !isset($opt[$option])) $opt[$option] = $entry[$key];
    }
    $pairs = array_merge($pairs, \pmssCgroupPolicyIoPairs($entry, $dev, false));
}

/** Apply numeric and mount defaults while retaining device validation and deduplication. */
function pmssCgroupCliDefaultsApply(SystemInterface $sys, array &$opt): array
{
    $policy = \pmssCgroupPolicyLoad();

    foreach (PMSS_CGROUP_POLICY_OPTIONS as $optionKey => $policyKey) {
        if (!isset($opt[$optionKey]) && isset($policy[$policyKey]) && is_numeric($policy[$policyKey])) {
            $opt[$optionKey] = (string)$policy[$policyKey];
        }
    }

    $pairsByKey = [];
    foreach (is_array($policy['mounts'] ?? null) ? $policy['mounts'] : [] as $mountPath => $mountPolicy) {
        if (!is_string($mountPath) || $mountPath === '' || !is_array($mountPolicy)) {
            continue;
        }

        $devicePath = strpos($mountPath, '/dev/') === 0
            ? trim($mountPath)
            : (pmssCgroupCliValidateDeviceSelector($mountPath) === null ? trim($sys->resolveDevice($mountPath)) : '');
        if ($devicePath === '' || !\pmssCgroupPolicyDeviceTargetIsSafe($devicePath)) {
            continue;
        }

        foreach (\pmssCgroupPolicyIoPairs($mountPolicy, $devicePath) as $pair) {
            $propertyName = substr($pair, 0, strpos($pair, '='));
            $pairsByKey[$propertyName.'|'.$devicePath] = $pair;
        }
    }

    return array_values($pairsByKey);
}
