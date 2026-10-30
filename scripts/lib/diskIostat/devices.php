<?php
/** Iostat device discovery, topology and command construction. @license GPL-3.0-only */
require_once dirname(__DIR__).'/cgroup/policy.php';
require_once dirname(__DIR__).'/blockDeviceLeaves.php';

/** Keep block device names argv-safe before passing them to iostat. */
function pmssDiskIostatDeviceNameIsSafe(string $device): bool
{
    return $device !== '' && preg_match('/\A[A-Za-z0-9._+-]+\z/', $device) === 1;
}

/**
 * Discover data block devices from /sys/block without crossing a shell.
 *
 * @return array<int, string>
 */
function pmssDiskIostatDiscoverDevices(string $sysBlockDir = '/sys/block'): array
{
    $devices = array_filter(pmssDirectoryEntriesRead($sysBlockDir) ?: [], static function (string $entry) use ($sysBlockDir): bool {
        return pmssDiskIostatDeviceNameIsSafe($entry)
            && pmssBlockDeviceNameIsDataDevice($entry)
            && is_dir($sysBlockDir.'/'.$entry);
    });

    sort($devices, SORT_NATURAL | SORT_FLAG_CASE);
    return $devices;
}

/**
 * Build the iostat command with every shell argument escaped.
 *
 * @param array<int, string> $devices
 */
function pmssDiskIostatBuildCommand(array $devices, string $iostatBinary = ''): string
{
    // Reject malformed executable paths before shell quoting can throw.
    if (pmssFilesystemPathHasNulByte($iostatBinary)) {
        throw new RuntimeException('Unsafe iostat binary path');
    }
    foreach ($devices as $device) {
        if (!is_string($device) || !pmssDiskIostatDeviceNameIsSafe($device)) {
            throw new RuntimeException('Unsafe block device name for iostat');
        }
    }

    if ($iostatBinary === '') $iostatBinary = pmssCommandPath('iostat') ?: 'iostat';

    return escapeshellarg($iostatBinary).' -xm 120 2 -g grp1'
        .($devices ? ' '.pmssCommandArgvShellQuote($devices) : '').' 2>&1';
}

/** Resolve only sampled leaf disks behind /home; unknown topology stays unknown. */
function pmssDiskIostatHomeDevices(array $sampled, string $sysClassBlock = '/sys/class/block', ?callable $mountRunner = null, string $devRoot = '/dev'): ?array
{
    $source = pmssCgroupPolicyMountSourceResolve('/home', $mountRunner);
    $devRoot = rtrim($devRoot, '/');
    if ($source === '' || pmssFilesystemPathHasNulByte($source) || $devRoot === ''
        || strpos($source, $devRoot.'/') !== 0 || pmssFilesystemPathHasNulByte($sysClassBlock)) return null;
    if (strpos($source, $devRoot.'/mapper/') === 0) {
        $source = (string) realpath($source);
    }
    $topology = pmssBlockDeviceLeaves(basename($source), rtrim($sysClassBlock, '/'), static function (string $pattern) { return @glob($pattern); });
    if (!$topology['complete'] || !$topology['leaves']) return null;
    foreach ($topology['leaves'] as $device) {
        if (!in_array($device, $sampled, true)) return null;
    }
    return $topology['leaves'];
}

/** Use /home leaves when resolvable; otherwise retain the discovered list. */
function pmssDiskIostatSampleDevices(array $devices, string $sysClassBlock = '/sys/class/block', ?callable $mountRunner = null, string $devRoot = '/dev'): array
{
    return pmssDiskIostatHomeDevices($devices, $sysClassBlock, $mountRunner, $devRoot) ?: $devices;
}

