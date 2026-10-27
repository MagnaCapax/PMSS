<?php
/** Host storage observations for legacy read-IOPS policy. @license GPL-3.0-only */
require_once __DIR__.'/../blockDeviceLeaves.php';

/** Extract a safe block device name from a /dev path. */
function pmssCgroupPolicyBlockDeviceName(string $devicePath): ?string
{
    $device = basename(trim($devicePath));
    return preg_match('/^[A-Za-z0-9._!+-]+\z/', $device) === 1 ? $device : null;
}

/** Return true when the /home source is an md device; derived caps wait for #623. */
function pmssCgroupPolicyHomeDeviceIsMdBacked(string $devicePath, string $sysClassBlock = '/sys/class/block'): bool
{
    $device = pmssCgroupPolicyBlockDeviceName($devicePath);
    return $device !== null && (preg_match('/^md\d+\z/', $device) === 1
        || is_dir(rtrim($sysClassBlock, '/').'/'.$device.'/md'));
}

/** Detect the class of the single-device /home backing block device. */
function pmssCgroupPolicyHomeStorageClassResolve(string $devicePath, string $sysClassBlock = '/sys/class/block'): ?string
{
    $device = pmssCgroupPolicyBlockDeviceName($devicePath);
    if ($device === null) return null;
    if (preg_match('/^nvme\d+n\d+(?:p\d+)?\z/', $device) === 1) return 'nvme';

    $root = rtrim($sysClassBlock, '/');
    $rotational = @file_get_contents($root.'/'.$device.'/queue/rotational');
    if (is_string($rotational) && trim($rotational) === '1') return 'hdd';
    $class = is_string($rotational) && trim($rotational) === '0' ? 'ssd' : null;
    // Visit every backing node so an intermediate queue retains its legacy
    // classification; the shared walk owns cycles, partitions, and leaf scans.
    pmssBlockDeviceLeaves($device, $root, static function (string $pattern) { return @glob($pattern); },
        static function (string $name) use ($root, &$class): void {
            if (preg_match('/^nvme\d+n\d+(?:p\d+)?\z/', $name) === 1) {
                if ($class !== 'hdd') $class = 'nvme';
                return;
            }
            $rotational = @file_get_contents($root.'/'.$name.'/queue/rotational');
            if (!is_string($rotational)) return;
            if (trim($rotational) === '1') $class = 'hdd';
            elseif (trim($rotational) === '0' && $class === null) $class = 'ssd';
        });
    return $class;
}
