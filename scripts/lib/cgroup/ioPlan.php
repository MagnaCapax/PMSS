<?php
/**
 * Cgroup CLI device resolution and IO planning.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

namespace PMSS\Cgroup;

/** Complete IO planning in order: selector, profile, latency, cost, then policy fallback. */
function pmssCgroupCliPlanIo(SystemInterface $sys, array &$plan, array $policyIoPairs): bool
{
    $device = $plan['io']['device'];
    if (($error = pmssCgroupCliValidateDeviceSelector($device)) !== null) {
        fwrite(STDERR, $error."\n");
        return false;
    }
    $resolved = '';
    if ($device !== '' || isset($plan['options']['io-latency-ms'])) {
        $resolved = strpos($device, '/dev/') === 0 ? $device : $sys->resolveDevice($device !== '' ? $device : '/home');
        if ($resolved !== '' && !\pmssCgroupPolicyDeviceTargetIsSafe($resolved)) {
            if ($device !== '') {
                fwrite(STDERR, "Invalid resolved --device target: expected /dev/... without whitespace or NUL bytes\n");
                return false;
            }
            echo "[WARN] IODeviceLatencyTargetSec skipped: unsafe /home backing device target\n";
            $resolved = '';
        }
    }
    if ($plan['io']['io-profile'] !== '' && $resolved !== '') pmssCgroupCliApplyIoProfile($plan, $resolved);
    if (isset($plan['options']['io-latency-ms']) && (int)$plan['options']['io-latency-ms'] > 0) {
        if ($plan['mode'] === 'v2' && $resolved !== '') {
            $plan['ioProperties'][] = 'IODeviceLatencyTargetSec='.$resolved.' '.(int)$plan['options']['io-latency-ms'].'ms';
        } elseif ($plan['mode'] !== 'v2') {
            echo "[SKIP] IODeviceLatencyTargetSec requires cgroup v2\n";
        }
    }
    $plan['ioCostWrites'] = pmssCgroupCliIoCostWrites($sys, $plan, $resolved);
    if (!empty($policyIoPairs) && empty($plan['ioProperties']) && $plan['io']['io-profile'] === '' && $device === '') $plan['ioProperties'] = $policyIoPairs;
    return true;
}

/**
 * Build io.cost write operations for qos/model with scheduler safeguards.
 *
 * Emit diagnostics in planning order and return only executable writes.
 * @return array<int,array{path:string,value:string}>
 */
function pmssCgroupCliIoCostWrites(SystemInterface $sys, array $plan, string $resolvedDevice): array
{
    if ($plan['io']['io-cost-qos'] === '' && $plan['io']['io-cost-model'] === '') return [];
    $writes = [];

    if ($plan['mode'] !== 'v2') {
        echo "[SKIP] io.cost requires cgroup v2\n";
        return [];
    }

    if ($resolvedDevice === '') {
        $resolvedDevice = trim((string) $sys->resolveDevice('/home'));
    }
    if ($resolvedDevice === '') {
        echo "[WARN] io.cost skipped: unable to resolve /home backing device\n";
        return [];
    }
    if (!\pmssCgroupPolicyDeviceTargetIsSafe($resolvedDevice)) {
        echo "[WARN] io.cost skipped: unsafe backing device target\n";
        return [];
    }

    // Resolve decimal major:minor via lsblk, then the existing sysfs fallback.
    $majorMinor = trim((string) $sys->execute('lsblk -dn -o MAJ:MIN '.escapeshellarg($resolvedDevice).' 2>/dev/null'));
    if (!\pmssCgroupPolicyMajorMinorIsValid($majorMinor)) {
        $blockName = basename($resolvedDevice);
        $majorMinor = strpos($resolvedDevice, '/dev/') === 0 && $blockName !== ''
            ? trim((string) $sys->readFile('/sys/class/block/'.$blockName.'/dev')) : '';
    }
    if (!\pmssCgroupPolicyMajorMinorIsValid($majorMinor)) {
        echo '[WARN] io.cost skipped: unable to resolve major:minor for '.$resolvedDevice."\n";
        return [];
    }

    $bfqProbe = trim((string) $sys->execute("grep -l '\\[bfq\\]' /sys/class/block/*/queue/scheduler 2>/dev/null | head -n 1"));
    if ($bfqProbe !== '') {
        echo '[SKIP] io.cost skipped: BFQ scheduler active ('.$bfqProbe.")\n";
        return [];
    }

    foreach (['io.cost.qos' => $plan['io']['io-cost-qos'], 'io.cost.model' => $plan['io']['io-cost-model']] as $fileName => $rawSetting) {
        $setting = trim((string) $rawSetting);
        if ($setting === '') {
            continue;
        }
        $majorMinorMatch = [];
        $hasMajorMinor = preg_match('/^([0-9]+:[0-9]+)\s+/', $setting, $majorMinorMatch) === 1;
        if ($hasMajorMinor && $majorMinorMatch[1] !== $majorMinor) {
            echo '[WARN] io.cost skipped: invalid '.$fileName." setting\n";
            continue;
        }
        $normalized = $hasMajorMinor ? $setting : $majorMinor.' '.$setting;

        $writes[] = ['path' => '/sys/fs/cgroup/'.$fileName, 'value' => $normalized];
        $slicePath = '/sys/fs/cgroup/user.slice/'.$plan['slice'].'/'.$fileName;
        if ($sys->readFile($slicePath) !== null) {
            $writes[] = ['path' => $slicePath, 'value' => $normalized];
        }
    }

    if (!empty($writes)) {
        echo '[INFO] io.cost target '.$resolvedDevice.' ('.$majorMinor.")\n";
    }

    return $writes;
}
