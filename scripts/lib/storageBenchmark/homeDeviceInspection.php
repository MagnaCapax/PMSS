<?php
/** Mounted-device inspection, without benchmark execution or file allocation. */

/** Resolve and inspect only the device reported by df; callers may inject probes for hermetic tests. */
function storageBenchmarkHomeDeviceResolve(
    string $targetDir,
    ?string $mountDevice = null,
    string $sysfsRoot = '/sys/block',
    ?callable $deviceCheck = null,
    ?callable $canonicalPath = null,
    ?callable $sizeRead = null
): array {
    $path = $mountDevice ?? storageBenchmarkCommandFieldRead(
        'df -P '.escapeshellarg($targetDir).' | awk '.escapeshellarg('NR==2 {print $1}')
    );
    $entry = ['device' => $path, 'rota' => null, 'size' => null, 'mode' => 'home-device'];
    if ($path === null || !storageBenchmarkDevicePathIsSafe($path)) {
        return $entry + ['ok' => false, 'error' => 'unsafe or unavailable mount device'];
    }

    if (!($deviceCheck ?: 'storageBenchmarkDeviceIsReadableBlock')($path)) return $entry + ['ok' => false, 'error' => 'mount device is not a readable block device'];

    $canonical = ($canonicalPath ?: 'realpath')($path);
    if (!is_string($canonical) || !storageBenchmarkDevicePathIsSafe($canonical)) {
        return $entry + ['ok' => false, 'error' => 'unable to resolve mount device'];
    }
    $size = ($sizeRead ?: 'storageBenchmarkDeviceSizeBytesRead')($path);
    if (!is_int($size) || $size <= 0) return $entry + ['ok' => false, 'error' => 'unable to determine block device size'];

    $sysfsDevice = rtrim($sysfsRoot, '/').'/'.basename($canonical);
    $rotational = @file_get_contents($sysfsDevice.'/queue/rotational');
    $entry['rota'] = $rotational === false || pmssFilesystemPathHasNulByte($rotational)
        || !in_array(trim($rotational), ['0', '1'], true) ? null : (int) trim($rotational);
    $entry['size'] = $size;
    if (is_dir($sysfsDevice.'/md')) {
        $state = @file_get_contents($sysfsDevice.'/md/array_state');
        $level = @file_get_contents($sysfsDevice.'/md/level');
        $degradedPath = $sysfsDevice.'/md/degraded';
        $degraded = @file_get_contents($degradedPath);
        // Reject raw NULs before trim can turn malformed metadata into a valid status.
        foreach ([$state, $level, $degraded] as $raw) {
            if (is_string($raw) && pmssFilesystemPathHasNulByte($raw)) {
                return $entry + ['ok' => false, 'error' => 'unable to read md array status'];
            }
        }
        if ($degraded === false && !file_exists($degradedPath) && $level !== false
            && in_array(trim($level), ['raid0', 'linear'], true)) {
            $degraded = '0';
        }
        // An overflowed sysfs counter cannot be reported as a reliable md status.
        $degradedCount = $degraded === false ? null : pmssUnsignedDecimalIntParse(trim($degraded));
        if ($state === false || $level === false || $degradedCount === null) {
            return $entry + ['ok' => false, 'error' => 'unable to read md array status'];
        }
        $entry['md'] = ['array_state' => trim($state), 'degraded' => $degradedCount, 'level' => trim($level)];
    }
    return $entry + ['ok' => true];
}
