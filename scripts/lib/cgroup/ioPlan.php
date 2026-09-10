<?php
/**
 * Cgroup CLI device resolution and IO planning.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

namespace PMSS\Cgroup;

/** Resolve one safe device; null means a fatal selector error, empty means no target. */
function pmssCgroupCliDeviceResolve(SystemInterface $sys, string $device, bool $needsHomeLatency): ?string
{
    if ($device === '' && !$needsHomeLatency) return '';
    $resolved = strpos($device, '/dev/') === 0 ? $device : $sys->resolveDevice($device !== '' ? $device : '/home');
    if ($resolved === '' || \pmssCgroupPolicyDeviceTargetIsSafe($resolved)) return $resolved;
    if ($device !== '') {
        fwrite(STDERR, "Invalid resolved --device target: expected /dev/... without whitespace or NUL bytes\n");
        return null;
    }
    echo "[WARN] IODeviceLatencyTargetSec skipped: unsafe /home backing device target\n";
    return '';
}

function pmssCgroupCliAppendIoLatencyPair(string $mode, string $devResolved, array $opt, array &$ioPairs): void
{
    if (!isset($opt['io-latency-ms']) || (int)$opt['io-latency-ms'] <= 0) return;
    if ($mode === 'v2' && $devResolved !== '') {
        $ioPairs[] = 'IODeviceLatencyTargetSec='.$devResolved.' '.(int)$opt['io-latency-ms'].'ms';
    } elseif ($mode !== 'v2') {
        echo "[SKIP] IODeviceLatencyTargetSec requires cgroup v2\n";
    }
}

/**
 * Build io.cost write operations for qos/model with scheduler safeguards.
 *
 * Emit diagnostics in planning order and return only executable writes.
 * @return array<int,array{path:string,value:string}>
 */
function pmssCgroupCliIoCostWrites(SystemInterface $sys, string $slice, string $mode, string $resolvedDevice, string $ioCostQos, string $ioCostModel): array
{
    if ($ioCostQos === '' && $ioCostModel === '') return [];
    $writes = [];

    if ($mode !== 'v2') {
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

    $majorMinor = pmssCgroupCliResolveIoCostMajorMinor($sys, $resolvedDevice);
    if ($majorMinor === '') {
        echo '[WARN] io.cost skipped: unable to resolve major:minor for '.$resolvedDevice."\n";
        return [];
    }

    $bfqProbe = trim((string) $sys->execute("grep -l '\\[bfq\\]' /sys/class/block/*/queue/scheduler 2>/dev/null | head -n 1"));
    if ($bfqProbe !== '') {
        echo '[SKIP] io.cost skipped: BFQ scheduler active ('.$bfqProbe.")\n";
        return [];
    }

    foreach (['io.cost.qos' => $ioCostQos, 'io.cost.model' => $ioCostModel] as $fileName => $rawSetting) {
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
        $slicePath = '/sys/fs/cgroup/user.slice/'.$slice.'/'.$fileName;
        if ($sys->readFile($slicePath) !== null) {
            $writes[] = ['path' => $slicePath, 'value' => $normalized];
        }
    }

    if (!empty($writes)) {
        echo '[INFO] io.cost target '.$resolvedDevice.' ('.$majorMinor.")\n";
    }

    return $writes;
}

/** Resolve device major:minor in decimal form for io.cost lines. */
function pmssCgroupCliResolveIoCostMajorMinor(SystemInterface $sys, string $devicePath): string
{
    $majorMinor = trim((string) $sys->execute('lsblk -dn -o MAJ:MIN '.escapeshellarg($devicePath).' 2>/dev/null'));
    if (preg_match('/^[0-9]+:[0-9]+$/', $majorMinor) === 1) {
        return $majorMinor;
    }

    if (strpos($devicePath, '/dev/') === 0) {
        $blockName = basename($devicePath);
        if ($blockName !== '') {
            $devValue = trim((string) $sys->readFile('/sys/class/block/'.$blockName.'/dev'));
            if (preg_match('/^[0-9]+:[0-9]+$/', $devValue) === 1) {
                return $devValue;
            }
        }
    }

    return '';
}
