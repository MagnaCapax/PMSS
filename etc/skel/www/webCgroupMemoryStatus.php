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
 * Uses the customer-side pmssFormatBytes copy from scriptsInc.php because
 * /scripts/lib/runtime.php is intentionally outside the customer PHP boundary.
 *
 * @license GPL-3.0-only
 */
require_once __DIR__.'/scriptsInc.php';

/** Format bytes into a compact human-readable string. */
function pmssWebCgroupMemoryStatusFormatBytes($bytes, $precision = 1)
{
    if (!is_numeric($bytes) || (float) $bytes < 0) {
        return 'n/a';
    }

    return pmssFormatBytes((float) $bytes, (int) $precision);
}

/** Return whether a v2 cgroup exposes the memory controller. */
function pmssWebCgroupMemoryStatusV2MemoryControllerAvailable($cgroupDir)
{
    if (!is_string($cgroupDir) || $cgroupDir === '') {
        return false;
    }

    $controllersPath = rtrim($cgroupDir, '/').'/cgroup.controllers';
    return is_file($controllersPath)
        && pmssCustomerCgroupDirOwnsMemoryController($cgroupDir);
}

/** Detect the readable user.slice directory for the current account. */
function pmssWebCgroupMemoryStatusDetectDir(array $overrides = [])
{
    if (isset($overrides['cgroup_dir']) && is_string($overrides['cgroup_dir']) && $overrides['cgroup_dir'] !== '') {
        return $overrides['cgroup_dir'];
    }

    $uid = $overrides['uid'] ?? (function_exists('posix_getuid') ? posix_getuid() : null);
    if (is_int($uid) && $uid >= 0) {
        $candidates = isset($overrides['cgroup_dir_candidates']) && is_array($overrides['cgroup_dir_candidates'])
            ? $overrides['cgroup_dir_candidates']
            : [
                '/sys/fs/cgroup/user.slice/user-'.$uid.'.slice',
                '/sys/fs/cgroup/memory/user.slice/user-'.$uid.'.slice',
                '/sys/fs/cgroup/unified/user.slice/user-'.$uid.'.slice',
            ];
        foreach ($candidates as $candidate) {
            if (pmssCustomerCgroupDirOwnsMemoryController($candidate)) {
                return $candidate;
            }
        }
    }

    $cgroupFile = (string) ($overrides['self_cgroup_file'] ?? '/proc/self/cgroup');
    foreach (pmssCustomerCgroupSelfEntries($cgroupFile) as $entry) {
        foreach (['/sys/fs/cgroup', '/sys/fs/cgroup/memory', '/sys/fs/cgroup/unified'] as $root) {
            $candidate = $root.$entry['path'];
            if (pmssCustomerCgroupDirOwnsMemoryController($candidate)) {
                return $candidate;
            }
        }
    }

    return '';
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

/**
 * cgroup-v1 fallback: read the real OOM-kill count from memory.oom_control on the account's
 * own slice AND its child user@<uid>.service slice. systemd can place the memcg limit (and
 * therefore the kills) on the child, so the parent slice can read oom_kill 0 while the child
 * recorded the kills — read both, take the max. Both files are world-readable (root:root 0644),
 * so this customer-tree helper reads them directly, no /scripts traversal (ADR-0016/0017).
 * memory.events (the v2 oom_kill source used in the reader above) does not exist on the
 * v1-pinned fleet; a REAL OOM kill is the ONLY sound per-account memory-pressure signal there
 * (failcnt false-HIGHs on page-cache reclaim and is deliberately NOT read). Returns 0 on v2.
 */
function pmssWebCgroupMemoryStatusV1OomKillRead($cgroupDir, $uid)
{
    if (!is_string($cgroupDir) || $cgroupDir === '') {
        return 0;
    }
    $cgroupDir = rtrim($cgroupDir, '/');
    $paths = array($cgroupDir.'/memory.oom_control');
    if (is_int($uid) && $uid >= 0) {
        $paths[] = $cgroupDir.'/user@'.$uid.'.service/memory.oom_control';
    }
    $max = 0;
    foreach ($paths as $path) {
        $value = pmssCustomerUnsignedIntegerValue((pmssCustomerKeyValueFileRead($path))['oom_kill'] ?? null);
        if ($value !== null && $value > $max) {
            $max = $value;
        }
    }
    return $max;
}

/** Classify current cgroup memory pressure into a user-facing level. */
function pmssWebCgroupMemoryStatusClassify(array $stats)
{
    if ((int) ($stats['oom_kill_events'] ?? 0) > 0) {
        return 'HIGH';
    }
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
    $cgroupAvailable = is_dir($cgroupDir);
    $uid = $overrides['uid'] ?? (function_exists('posix_getuid') ? posix_getuid() : null);
    $uid = is_int($uid) && $uid >= 0 ? $uid : null;
    $memory = pmssCustomerCgroupMemoryRead($uid, rtrim($cgroupDir, '/'), $cgroupAvailable);
    $events = $cgroupAvailable ? pmssCustomerKeyValueFileRead($cgroupDir.'/memory.events') : [];
    $pressure = $cgroupAvailable && pmssWebCgroupMemoryStatusV2MemoryControllerAvailable($cgroupDir)
        ? pmssCustomerKeyValueFileRead($cgroupDir.'/memory.pressure')
        : [];
    $memoryBreakdown = [];
    if ($cgroupAvailable) {
        foreach (pmssCustomerCgroupCounterPaths($uid, rtrim($cgroupDir, '/'), 'memory.stat', 'memory', 'memory.stat') as $path) {
            $raw = @file_get_contents($path);
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }

            $memoryBreakdown = pmssWebCgroupMemoryStatusMemoryStatBreakdownParse($raw);
            if (isset($memoryBreakdown['anon'])) {
                break;
            }
        }
    }

    // Build the returned fields once; classification substitutes only its anon-based inputs.
    $stats = ['available' => $cgroupDir !== '' && $memory['memory_current'] !== null, 'cgroup_dir' => $cgroupDir] + $memory;
    $stats['limit_bytes'] = $memory['memory_max'] ?? $memory['memory_high'];
    $stats['limit_source'] = $memory['memory_max'] !== null ? 'memory.max' : ($memory['memory_high'] !== null ? 'memory.high' : '');
    $memoryPressureCurrent = isset($memoryBreakdown['anon']) ? (float) $memoryBreakdown['anon'] : $memory['memory_current'];
    foreach ([
        'usage_percent' => [$memory['memory_current'], $stats['limit_bytes']],
        'high_percent' => [$memory['memory_current'], $memory['memory_high']],
        'pressure_usage_percent' => [$memoryPressureCurrent, $stats['limit_bytes']],
        'pressure_high_percent' => [$memoryPressureCurrent, $memory['memory_high']],
    ] as $key => [$current, $limit]) {
        $stats[$key] = $current !== null && $limit !== null && $limit > 0 ? round(($current / $limit) * 100, 1) : null;
    }
    foreach (['some', 'full'] as $key) {
        $stats['pressure_'.$key.'_avg10'] = isset($pressure[$key]) && preg_match('/avg10=([0-9.]+)/', $pressure[$key], $matches) === 1 ? (float) $matches[1] : null;
    }
    foreach (['throttle' => 'high', 'max' => 'max', 'oom' => 'oom', 'oom_kill' => 'oom_kill'] as $key => $event) {
        $stats[$key.'_events'] = pmssCustomerUnsignedIntegerValue($events[$event] ?? null) ?? ($key === 'throttle' ? null : 0);
    }
    // cgroup-v1 fleet (ADR-0019): memory.events is absent, so the v2 oom_kill above is 0.
    // Fall back to the real OOM-kill count from memory.oom_control (the only sound v1 signal).
    if ($stats['oom_kill_events'] === 0 && !pmssWebCgroupMemoryStatusV2MemoryControllerAvailable($cgroupDir)) {
        $stats['oom_kill_events'] = pmssWebCgroupMemoryStatusV1OomKillRead($cgroupDir, $uid);
    }
    $status = pmssWebCgroupMemoryStatusClassify(array_replace($stats, [
        'memory_current' => $memoryPressureCurrent,
        'usage_percent' => $stats['pressure_usage_percent'],
        'high_percent' => $stats['pressure_high_percent'],
    ]));

    return $stats + [
        'status' => $status,
        'status_color' => ['LOW' => '#81c784', 'MEDIUM' => '#ffb74d', 'HIGH' => '#ef5350', 'THROTTLED' => '#d2691e'][$status] ?? '#b0bec5',
        'message' => $stats['oom_kill_events'] > 0
            ? 'Your account reached its memory limit and had processes stopped (out-of-memory) '.number_format($stats['oom_kill_events']).' time(s). If transfers or apps keep getting interrupted, adding Extra RAM from your Upgrade Options raises the limit for this service.'
            : ($status === 'THROTTLED'
                ? 'Your service is running at reduced speed due to memory pressure. Reducing active tasks or upgrading your plan will restore full speed.'
                : ($status === 'HIGH' ? 'Memory usage is close to the account limit.' : '')),
        'usage_text' => ($stats['memory_current'] !== null ? pmssWebCgroupMemoryStatusFormatBytes($stats['memory_current']) : 'n/a')
            .' / '.($stats['limit_bytes'] !== null ? pmssWebCgroupMemoryStatusFormatBytes($stats['limit_bytes']) : 'n/a')
            .($stats['usage_percent'] !== null ? ' ('.number_format($stats['usage_percent'], 1, '.', '').'%' : '')
            .($stats['pressure_usage_percent'] !== null && $stats['pressure_usage_percent'] !== $stats['usage_percent']
                ? '; pressure '.number_format($stats['pressure_usage_percent'], 1, '.', '').'%'
                : '')
            .($stats['usage_percent'] !== null ? ')' : ''),
    ];
}

/**
 * Gather the welcome-page RAM counters from customer-readable sources.
 *
 * @return array<string,mixed>
 */
function pmssWelcomeMemoryStateBuild($pressureStatusOverride = null)
{
    $memory = pmssCustomerSerializedArrayFileRead('../.resourceData', 1048576);
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
        $uidValue = is_string($uidRaw) ? pmssCustomerUnsignedIntegerValue(trim($uidRaw)) : null;
        if ($uidValue !== null) $uid = $uidValue;
    }
    $uid = is_int($uid) && $uid >= 0 ? $uid : null;

    $readPressureStatus = null;
    if (function_exists('pmssWebCgroupMemoryStatusRead')) {
        $readPressureStatus = pmssWebCgroupMemoryStatusRead();
        if ($currentBytes === null && !empty($readPressureStatus['available']) && is_numeric($readPressureStatus['memory_current'] ?? null)) {
            $currentBytes = (float) $readPressureStatus['memory_current'];
        }
    }

    if (!isset($breakdown['anon'], $breakdown['file']) && $uid !== null) {
        foreach (pmssCustomerCgroupCounterPaths($uid, '', 'memory.stat', 'memory', 'memory.stat') as $path) {
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
    } elseif (is_array($readPressureStatus)) {
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
            '<br /><b>Memory pressure:</b> <span style="color: '.$pressureStatus['status_color'].';">&#9679; '.pmssCustomerHtmlAttr($pressureStatus['status']).'</span>',
        );
        if ($pressureStatus['throttle_events'] !== null) {
            $pressureParts[] = '<br />Throttle events: '.number_format((int) $pressureStatus['throttle_events']);
        }
        if ($pressureStatus['message'] !== '') {
            $pressureParts[] = '<br /><b style="color: '.$pressureStatus['status_color'].';">'.pmssCustomerHtmlAttr($pressureStatus['message']).'</b>';
        }
        $pressureIndicator = implode('', $pressureParts).'<br />';
    }

    return pmssWelcomeMetricSectionHtmlBuild('RAM Info', "\n{$gauge}\n{$pressureIndicator}\n{$warning}\n");
}
