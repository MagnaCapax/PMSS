<?php
/**
 * User-facing cgroup memory pressure helpers for first-party web pages.
 *
 * Reads the account slice counters directly from cgroupfs and returns a small,
 * render-friendly status array without shelling out.
 *
 * Lives in etc/skel/www/ (customer tree) because customer PHP runs as the
 * customer UID and cannot traverse /scripts/ (the operator-only tree). The
 * cgroup files read here (/sys/fs/cgroup/user.slice/user-<UID>.slice/memory.*)
 * are world-readable kernel paths; the customer can read their own slice.
 *
 * Carries the customer-side pmssFormatBytes copy because /scripts/lib/runtime.php
 * is intentionally outside the customer PHP trust boundary.
 *
 * @license GPL-3.0-only
 */

if (!function_exists('pmssFormatBytes')) {
    /** Compact human-readable bytes for customer-facing status output. */
    function pmssFormatBytes($bytes, $precision = 1, $minimumUnitIndex = 0, $trimTrailingZeros = false)
    {
        $units = array('B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB');
        $bytes = max((float) $bytes, 0.0);
        $index = 0;
        $minimumUnitIndex = max(0, min((int) $minimumUnitIndex, count($units) - 1));
        while ($index < $minimumUnitIndex && $index < count($units) - 1) {
            $bytes /= 1024.0;
            $index++;
        }
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024.0;
            $index++;
        }

        $formatted = number_format($bytes, (int) $precision, '.', '');
        if ($trimTrailingZeros) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return ($formatted === '' ? '0' : $formatted).' '.$units[$index];
    }
}

/** Format bytes into a compact human-readable string. */
function pmssWebCgroupMemoryStatusFormatBytes($bytes, $precision = 1)
{
    if (!is_numeric($bytes) || (float) $bytes < 0) {
        return 'n/a';
    }

    return pmssFormatBytes((float) $bytes, (int) $precision);
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

/** Return candidate memory.stat paths for the current account slice. */
function pmssWebCgroupMemoryStatusMemoryStatCandidatePaths($uid)
{
    $uid = (int) $uid;
    return $uid >= 0 ? [
        '/sys/fs/cgroup/user.slice/user-'.$uid.'.slice/memory.stat',
        '/sys/fs/cgroup/unified/user.slice/user-'.$uid.'.slice/memory.stat',
        '/sys/fs/cgroup/memory/user.slice/user-'.$uid.'.slice/memory.stat',
    ] : [];
}

/** Parse cgroup v1/v2 memory.stat anon+file counters into byte values. */
function pmssWebCgroupMemoryStatusMemoryStatBreakdownParse($raw)
{
    $breakdown = [];
    if (!is_string($raw) || trim($raw) === '') {
        return $breakdown;
    }

    foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
        if (count($parts = preg_split('/\s+/', trim($line), 2)) !== 2 || !ctype_digit($parts[1])) {
            continue;
        }
        if ($parts[0] === 'anon' || $parts[0] === 'total_rss') {
            $breakdown['anon'] = (float) $parts[1];
        } elseif ($parts[0] === 'file' || $parts[0] === 'total_cache') {
            $breakdown['file'] = (float) $parts[1];
        }
    }

    return $breakdown;
}

/** Classify current cgroup memory pressure into a user-facing level. */
function pmssWebCgroupMemoryStatusClassify(array $stats)
{
    $usagePercent = $stats['usage_percent'];
    $highPercent = $stats['high_percent'];
    $pressureSome = $stats['pressure_some_avg10'];
    $pressureFull = $stats['pressure_full_avg10'];
    $throttleEvents = $stats['throttle_events'];

    if ($stats['memory_current'] !== null
        && $stats['memory_high'] !== null
        && $throttleEvents > 0
        && ($stats['memory_current'] >= $stats['memory_high']
            || ($throttleEvents > 1000 && $highPercent !== null && $highPercent >= 95.0))) {
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
    $pressureSomeAvg10 = isset($pressure['some']) && preg_match('/avg10=([0-9.]+)/', $pressure['some'], $matches) === 1 ? (float) $matches[1] : null;
    $pressureFullAvg10 = isset($pressure['full']) && preg_match('/avg10=([0-9.]+)/', $pressure['full'], $matches) === 1 ? (float) $matches[1] : null;
    $status = pmssWebCgroupMemoryStatusClassify([
        'memory_current' => $memoryCurrent,
        'memory_high' => $memoryHigh,
        'usage_percent' => $usagePercent,
        'high_percent' => $highPercent,
        'pressure_some_avg10' => $pressureSomeAvg10,
        'pressure_full_avg10' => $pressureFullAvg10,
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
        'pressure_some_avg10' => $pressureSomeAvg10,
        'pressure_full_avg10' => $pressureFullAvg10,
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

/**
 * Gather the welcome-page RAM counters from customer-readable sources.
 *
 * @return array<string,mixed>
 */
function pmssWelcomeMemoryStateBuild($pressureStatusOverride = null)
{
    $memory = pmssWelcomeSerializedArrayRead('../.resourceData');
    $memory = is_array($memory) && isset($memory['memory']) && is_array($memory['memory'])
        ? $memory['memory']
        : array();
    $currentBytes = isset($memory['current']) && is_numeric($memory['current'])
        ? (float) $memory['current']
        : null;
    $breakdown = array();
    foreach (array('anon', 'file') as $key) {
        if (isset($memory[$key]) && is_numeric($memory[$key])) {
            $breakdown[$key] = (float) $memory[$key];
        }
    }

    $uid = function_exists('posix_getuid') ? (int) posix_getuid() : null;
    if ($uid === null
        && function_exists('pmssFrontendShellExecAvailable')
        && function_exists('pmssFrontendShellExec')
        && pmssFrontendShellExecAvailable()) {
        $uidRaw = @pmssFrontendShellExec('/usr/bin/id -u 2>/dev/null');
        if (is_string($uidRaw) && ctype_digit(trim($uidRaw))) {
            $uid = (int) trim($uidRaw);
        }
    }
    $uid = is_int($uid) && $uid >= 0 ? $uid : null;

    if ($currentBytes === null
        && $uid !== null
        && function_exists('pmssFrontendShellExecAvailable')
        && function_exists('pmssFrontendShellExec')
        && pmssFrontendShellExecAvailable()) {
        $memoryCurrent = @pmssFrontendShellExec('systemctl show user-'.$uid.'.slice -p MemoryCurrent --value 2>/dev/null');
        if (is_string($memoryCurrent) && is_numeric(trim($memoryCurrent))) {
            $currentBytes = (float) trim($memoryCurrent);
        }
    }

    if (!isset($breakdown['anon'], $breakdown['file']) && $uid !== null) {
        foreach (pmssWebCgroupMemoryStatusMemoryStatCandidatePaths($uid) as $path) {
            $raw = @file_get_contents($path);
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }

            $parsed = pmssWebCgroupMemoryStatusMemoryStatBreakdownParse($raw);
            if (isset($parsed['anon'], $parsed['file'])) {
                $breakdown = $parsed;
                break;
            }
        }
    }

    $ramMiB = pmssWelcomeUserConfigNumber('ramMiB');
    $pressureStatus = null;
    if (is_array($pressureStatusOverride)) {
        $pressureStatus = !empty($pressureStatusOverride['available']) ? $pressureStatusOverride : null;
    } elseif (function_exists('pmssWebCgroupMemoryStatusRead')) {
        $readPressureStatus = pmssWebCgroupMemoryStatusRead();
        if (!empty($readPressureStatus['available'])) {
            $pressureStatus = $readPressureStatus;
        }
    }

    return array(
        'currentBytes' => $currentBytes,
        'limitBytes' => $ramMiB !== null && $ramMiB > 0 ? $ramMiB * 1024 * 1024 : null,
        'processBytes' => isset($breakdown['anon']) ? (float) $breakdown['anon'] : null,
        'cacheBytes' => isset($breakdown['file']) ? (float) $breakdown['file'] : null,
        'pressureStatus' => $pressureStatus,
    );
}

/** Render the welcome-page RAM usage section. */
function pmssWelcomeMemorySectionHtmlBuild($pressureStatusOverride = null)
{
    $state = pmssWelcomeMemoryStateBuild($pressureStatusOverride);
    $currentBytes = $state['currentBytes'];
    $limitBytes = $state['limitBytes'];
    $processBytes = $state['processBytes'];
    $cacheBytes = $state['cacheBytes'];
    $pressureStatus = $state['pressureStatus'];

    if ($currentBytes === null && $limitBytes === null && $processBytes === null && $cacheBytes === null) {
        return pmssWelcomeMetricSectionHtmlBuild('RAM Info', '<b>RAM usage data is unavailable right now.</b>');
    }

    $currentText = $currentBytes === null ? 'n/a' : pmssFormatBytes($currentBytes, 2, 0, true);
    $processText = $processBytes === null ? 'n/a' : pmssFormatBytes($processBytes, 2, 0, true);
    $cacheText = $cacheBytes === null ? 'n/a' : pmssFormatBytes($cacheBytes, 2, 0, true);
    if ($limitBytes === null || $limitBytes <= 0) {
        $breakdownText = ($processBytes !== null || $cacheBytes !== null)
            ? '<br />Process memory: '.$processText.'<br />Page cache: '.$cacheText
            : '';
        return pmssWelcomeMetricSectionHtmlBuild('RAM Info', "\nCurrent RAM usage: {$currentText}{$breakdownText}<br />\nRAM limit: n/a\n");
    }

    $limitText = pmssFormatBytes($limitBytes, 2, 0, true);
    if ($currentBytes === null && $processBytes === null && $cacheBytes === null) {
        return pmssWelcomeMetricSectionHtmlBuild('RAM Info', "\nCurrent RAM usage: n/a<br />\nRAM limit: {$limitText}\n");
    }

    $warningBytes = $processBytes !== null ? $processBytes : $currentBytes;
    $warningPercent = pmssWelcomePercent($warningBytes, $limitBytes, 1);
    if ($processBytes !== null && $cacheBytes !== null) {
        $usedBytes = max($processBytes + $cacheBytes, $currentBytes !== null ? $currentBytes : 0);
        $processPercent = pmssWelcomePercent($processBytes, $limitBytes, 1);
        $cachePercent = pmssWelcomePercent($cacheBytes, $limitBytes, 1);
        $titleText = 'Process: '.$processText.' | Cache: '.$cacheText.' | Limit: '.$limitText;
        $gauge = createStackedGauge(
            $titleText,
            $titleText,
            pmssWelcomePercent($usedBytes, $limitBytes, 1),
            array(
                array('width' => $processPercent, 'color' => '#'.gaugeColor($warningPercent)),
                array('width' => $cachePercent, 'color' => '#b0bec5'),
                array('width' => max(0, 100 - max(0, min(100, $processPercent)) - max(0, min(100, $cachePercent))), 'color' => 'transparent'),
            )
        );
    } else {
        $titleText = "{$currentText} / {$limitText}";
        $gauge = createGauge($titleText, $titleText, pmssWelcomePercent($currentBytes !== null ? $currentBytes : 0, $limitBytes, 1));
    }

    $hasOomEvents = is_array($pressureStatus)
        && ((int) ($pressureStatus['max_events'] ?? 0) > 0
            || (int) ($pressureStatus['oom_events'] ?? 0) > 0
            || (int) ($pressureStatus['oom_kill_events'] ?? 0) > 0);
    $isThrottleActive = is_array($pressureStatus)
        && (string) ($pressureStatus['status'] ?? '') === 'THROTTLED'
        && !$hasOomEvents;
    if ($isThrottleActive) {
        $warning = '<br /><b style="color: #d2691e;">RAM THROTTLE ACTIVE</b><br />Your service is running at reduced speed due to memory pressure. Reducing active tasks or upgrading your plan will restore full speed.<br />';
    } elseif ($warningPercent > 100) {
        $warning = '<br /><b style="color: red;">RAM LIMIT EXCEEDED</b><br />Processes may be killed (OOM) until memory usage drops.<br />';
    } elseif ($warningPercent >= 80) {
        $warning = '<br /><b style="color: #d2691e;">RAM WARNING</b><br />You are close to your RAM limit. Consider reducing running services or upgrading your plan.<br />';
    } else {
        $warning = '';
    }

    $pressureIndicator = '';
    if (is_array($pressureStatus)) {
        $pressureParts = array(
            '<br /><b>Memory pressure:</b> <span style="color: '.$pressureStatus['status_color'].';">&#9679; '.htmlspecialchars($pressureStatus['status'], ENT_QUOTES, 'UTF-8').'</span>',
            '<br />Throttle events: '.number_format((int) $pressureStatus['throttle_events']),
        );
        if ($pressureStatus['message'] !== '') {
            $pressureParts[] = '<br /><b style="color: '.$pressureStatus['status_color'].';">'.htmlspecialchars($pressureStatus['message'], ENT_QUOTES, 'UTF-8').'</b>';
        }
        $pressureIndicator = implode('', $pressureParts).'<br />';
    }

    return pmssWelcomeMetricSectionHtmlBuild('RAM Info', "\n{$gauge}\n{$pressureIndicator}\n{$warning}\n");
}
