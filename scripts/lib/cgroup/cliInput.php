<?php
/**
 * Cgroup CLI input parsing and validation.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

namespace PMSS\Cgroup;

const PMSS_CGROUP_INTEGER_OPTIONS = ['cpu-weight', 'io-weight', 'tasks-max', 'memory-high', 'memory-max', 'io-latency-ms'];

const PMSS_CGROUP_IO_FLAGS = ['io-read-bw' => 'IOReadBandwidthMax', 'io-write-bw' => 'IOWriteBandwidthMax', 'io-read-iops' => 'IOReadIOPSMax', 'io-write-iops' => 'IOWriteIOPSMax'];

/** Parse scalar options once; repeated IO flags retain their historical grouping order. */
function pmssCgroupCliParseFlagInputs(SystemInterface $sys, array $flags, ?string &$error): array
{
    $error = null;
    $options = $ioSpecs = $ioPairs = [];
    foreach ($flags as $flag) {
        if (strpos($flag, '--') !== 0 || ($separator = strpos($flag, '=')) === false) {
            continue;
        }

        $name = substr($flag, 2, $separator - 2);
        $value = substr($flag, $separator + 1);
        if (isset(PMSS_CGROUP_IO_FLAGS[$name])) {
            $ioSpecs[$name][] = $value;
            continue;
        }

        $options[$name] = $value;
    }

    foreach (PMSS_CGROUP_IO_FLAGS as $flagName => $propertyName) {
        foreach ($ioSpecs[$flagName] ?? [] as $spec) {
            $specText = trim($spec);
            // /home: shorthand — generalized to accept "max" (clear) or positive integer (apply cap).
            // Resolves /home backing device on the host at apply-time; hallinta + iopsLimitEnforcer
            // can stay device-agnostic. Matches /dev/<device>:N format for explicit-device callers.
            if (in_array($flagName, ['io-read-iops', 'io-write-iops'], true)
                && preg_match('#^/home:(.+)$#', $specText, $homeMatches) === 1) {
                $homeDevice = trim($sys->resolveDevice('/home'));
                if ($homeDevice === '' || !\pmssCgroupPolicyDeviceTargetIsSafe($homeDevice)) {
                    $error = 'Invalid --'.$flagName.' /home shorthand: unable to resolve safe backing device';
                    return [];
                }
                $rawValue = $homeMatches[1];
                if ($rawValue === 'max') {
                    $resolvedValue = 'infinity';
                } elseif (preg_match('/^[0-9]+$/', $rawValue) === 1) {
                    $resolvedValue = $rawValue;
                } else {
                    $error = 'Invalid --'.$flagName.' /home value: expected positive integer or "max"';
                    return [];
                }
                $ioPairs[] = $propertyName.'='.$homeDevice.' '.$resolvedValue;
                continue;
            }

            if (preg_match('/^([^:\s]+):([^\s]+)$/', $specText, $matches) !== 1
                || !\pmssCgroupPolicyDeviceTargetIsSafe($matches[1])
                || strpos($matches[2], "\0") !== false) {
                $error = 'Invalid --'.$flagName.' specification: '.$spec;
                return [];
            }
            $ioPairs[] = $propertyName.'='.$matches[1].' '.$matches[2];
        }
    }

    return ['options' => $options, 'io' => $ioPairs];
}

/**
 * Reject malformed CLI values before they reach systemctl.
 */
function pmssCgroupCliValidateFlagOptions(array $opt, string $ioCostQos, string $ioCostModel): ?string
{
    foreach (PMSS_CGROUP_INTEGER_OPTIONS as $key) {
        if (isset($opt[$key]) && preg_match('/^-?[0-9]+$/', (string)$opt[$key]) !== 1) {
            return 'Invalid --'.$key.' value: expected integer';
        }
    }

    if (isset($opt['io-latency-ms']) && (int)$opt['io-latency-ms'] <= 0) {
        return 'Invalid --io-latency-ms value: expected positive integer';
    }

    foreach (['io-cost-qos' => $ioCostQos, 'io-cost-model' => $ioCostModel] as $flagName => $value) {
        if ($value !== '' && preg_match('/[\r\n\0]/', $value) === 1) {
            return 'Invalid --'.$flagName.' value: newline and NUL bytes are not allowed';
        }
    }

    if (!isset($opt['cpu-quota-percent'])) {
        return null;
    }

    $quota = (string)$opt['cpu-quota-percent'];
    if (strtolower($quota) === 'infinity' || preg_match('/^-?[0-9]+$/', $quota) === 1) {
        return null;
    }

    return 'Invalid --cpu-quota-percent value: expected integer or infinity';
}

/** Reject shell-sensitive device selectors before mount resolution. */
function pmssCgroupCliValidateDeviceSelector(string $device): ?string
{
    if ($device === '') {
        return null;
    }

    if (strpos($device, "\0") !== false || preg_match('/\s/', $device) === 1) {
        return 'Invalid --device value: whitespace or NUL bytes are not allowed';
    }

    return null;
}
