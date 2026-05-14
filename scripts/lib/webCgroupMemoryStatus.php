<?php
/**
 * User-facing cgroup memory pressure helpers for first-party web pages.
 *
 * Reads the account slice counters directly from cgroupfs and returns a small,
 * render-friendly status array without shelling out.
 *
 * @license GPL-3.0-only
 */

/** Format bytes into a compact human-readable string. */
function pmssWebCgroupMemoryStatusFormatBytes($bytes, $precision = 1)
{
    if (!is_numeric($bytes) || (float) $bytes < 0) {
        return 'n/a';
    }

    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
    $value = (float) $bytes;
    $unitIndex = 0;
    while ($value >= 1024.0 && $unitIndex < count($units) - 1) {
        $value /= 1024.0;
        $unitIndex++;
    }

    return number_format($value, $unitIndex === 0 ? 0 : (int) $precision, '.', '').' '.$units[$unitIndex];
}

/** Detect the readable user.slice directory for the current account. */
function pmssWebCgroupMemoryStatusDetectDir(array $overrides = [])
{
    if (isset($overrides['cgroup_dir']) && is_string($overrides['cgroup_dir']) && $overrides['cgroup_dir'] !== '') {
        return $overrides['cgroup_dir'];
    }

    $uid = $overrides['uid'] ?? (function_exists('posix_getuid') ? posix_getuid() : null);
    if (is_int($uid) && $uid >= 0) {
        foreach (['/sys/fs/cgroup/user.slice/user-'.$uid.'.slice', '/sys/fs/cgroup/unified/user.slice/user-'.$uid.'.slice'] as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }
    }

    $cgroupFile = (string) ($overrides['self_cgroup_file'] ?? '/proc/self/cgroup');
    $lines = @file($cgroupFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return '';
    }

    foreach ($lines as $line) {
        $parts = explode(':', (string) $line, 3);
        if (count($parts) !== 3 || trim($parts[2]) === '') {
            continue;
        }

        foreach (['/sys/fs/cgroup', '/sys/fs/cgroup/unified'] as $root) {
            $candidate = $root.'/'.ltrim(trim($parts[2]), '/');
            if (is_dir($candidate)) {
                return $candidate;
            }
        }
    }

    return '';
}

/** Parse a simple whitespace-delimited key/value file into an array. */
function pmssWebCgroupMemoryStatusReadMap($path)
{
    $raw = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $map = [];
    foreach (is_array($raw) ? $raw : [] as $line) {
        $parts = preg_split('/\s+/', trim((string) $line), 2);
        if (count($parts) !== 2) {
            continue;
        }
        $map[$parts[0]] = $parts[1];
    }

    return $map;
}

/** Classify current cgroup memory pressure into a user-facing level. */
function pmssWebCgroupMemoryStatusClassify(array $stats)
{
    $usagePercent = $stats['usage_percent'];
    $highPercent = $stats['high_percent'];
    $pressureSome = $stats['pressure_some_avg10'];
    $pressureFull = $stats['pressure_full_avg10'];

    if ($stats['memory_current'] !== null
        && $stats['memory_high'] !== null
        && $stats['memory_current'] >= $stats['memory_high']
        && $stats['throttle_events'] > 0) {
        return 'THROTTLED';
    }

    if (($pressureFull !== null && $pressureFull >= 0.10)
        || ($pressureSome !== null && $pressureSome >= 1.0)
        || ($usagePercent !== null && $usagePercent >= 95.0)
        || ($highPercent !== null && $highPercent >= 95.0)) {
        return 'HIGH';
    }

    if (($pressureSome !== null && $pressureSome >= 0.25)
        || ($usagePercent !== null && $usagePercent >= 80.0)
        || ($highPercent !== null && $highPercent >= 80.0)) {
        return 'MEDIUM';
    }

    return 'LOW';
}

/**
 * Read cgroup memory pressure and throttle counters for the current account.
 *
 * @return array<string, mixed>
 */
function pmssWebCgroupMemoryStatusRead(array $overrides = [])
{
    $cgroupDir = pmssWebCgroupMemoryStatusDetectDir($overrides);
    $memoryCurrentRaw = is_dir($cgroupDir) ? trim((string) @file_get_contents($cgroupDir.'/memory.current')) : '';
    $memoryHighRaw = is_dir($cgroupDir) ? trim((string) @file_get_contents($cgroupDir.'/memory.high')) : '';
    $memoryMaxRaw = is_dir($cgroupDir) ? trim((string) @file_get_contents($cgroupDir.'/memory.max')) : '';
    $events = is_dir($cgroupDir) ? pmssWebCgroupMemoryStatusReadMap($cgroupDir.'/memory.events') : [];
    $pressure = is_dir($cgroupDir) ? pmssWebCgroupMemoryStatusReadMap($cgroupDir.'/memory.pressure') : [];

    $memoryCurrent = ctype_digit($memoryCurrentRaw) ? (int) $memoryCurrentRaw : null;
    $memoryHigh = ctype_digit($memoryHighRaw) ? (int) $memoryHighRaw : null;
    $memoryMax = ctype_digit($memoryMaxRaw) ? (int) $memoryMaxRaw : null;
    $throttleEvents = isset($events['high']) && ctype_digit((string) $events['high']) ? (int) $events['high'] : 0;
    $maxEvents = isset($events['max']) && ctype_digit((string) $events['max']) ? (int) $events['max'] : 0;
    $oomEvents = isset($events['oom']) && ctype_digit((string) $events['oom']) ? (int) $events['oom'] : 0;
    $oomKillEvents = isset($events['oom_kill']) && ctype_digit((string) $events['oom_kill']) ? (int) $events['oom_kill'] : 0;
    $limitBytes = $memoryMax !== null ? $memoryMax : $memoryHigh;
    $usagePercent = ($memoryCurrent !== null && $limitBytes !== null && $limitBytes > 0)
        ? round(($memoryCurrent / $limitBytes) * 100, 1)
        : null;
    $highPercent = ($memoryCurrent !== null && $memoryHigh !== null && $memoryHigh > 0)
        ? round(($memoryCurrent / $memoryHigh) * 100, 1)
        : null;
    $status = pmssWebCgroupMemoryStatusClassify([
        'memory_current' => $memoryCurrent,
        'memory_high' => $memoryHigh,
        'usage_percent' => $usagePercent,
        'high_percent' => $highPercent,
        'pressure_some_avg10' => isset($pressure['some']) && preg_match('/avg10=([0-9.]+)/', $pressure['some'], $matches) === 1 ? (float) $matches[1] : null,
        'pressure_full_avg10' => isset($pressure['full']) && preg_match('/avg10=([0-9.]+)/', $pressure['full'], $matches) === 1 ? (float) $matches[1] : null,
        'throttle_events' => $throttleEvents,
    ]);

    return [
        'available' => $cgroupDir !== '' && $memoryCurrent !== null,
        'cgroup_dir' => $cgroupDir,
        'memory_current' => $memoryCurrent,
        'memory_high' => $memoryHigh,
        'memory_max' => $memoryMax,
        'limit_bytes' => $limitBytes,
        'limit_source' => $memoryMax !== null ? 'memory.max' : ($memoryHigh !== null ? 'memory.high' : ''),
        'usage_percent' => $usagePercent,
        'high_percent' => $highPercent,
        'pressure_some_avg10' => isset($pressure['some']) && preg_match('/avg10=([0-9.]+)/', $pressure['some'], $matches) === 1 ? (float) $matches[1] : null,
        'pressure_full_avg10' => isset($pressure['full']) && preg_match('/avg10=([0-9.]+)/', $pressure['full'], $matches) === 1 ? (float) $matches[1] : null,
        'throttle_events' => $throttleEvents,
        'max_events' => $maxEvents,
        'oom_events' => $oomEvents,
        'oom_kill_events' => $oomKillEvents,
        'status' => $status,
        'status_color' => ['LOW' => '#81c784', 'MEDIUM' => '#ffb74d', 'HIGH' => '#ef5350', 'THROTTLED' => '#d2691e'][$status] ?? '#b0bec5',
        'message' => $status === 'THROTTLED'
            ? 'Your service is running at reduced speed due to memory pressure. Reducing active tasks or upgrading your plan will restore full speed.'
            : ($status === 'HIGH' ? 'Memory usage is close to the account limit.' : ''),
        'usage_text' => ($memoryCurrent !== null ? pmssWebCgroupMemoryStatusFormatBytes($memoryCurrent) : 'n/a')
            .' / '.($limitBytes !== null ? pmssWebCgroupMemoryStatusFormatBytes($limitBytes) : 'n/a')
            .($usagePercent !== null ? ' ('.number_format($usagePercent, 1, '.', '').'%)' : ''),
    ];
}
