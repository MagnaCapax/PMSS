<?php
/** Assemble the legacy status payload from customer counters and pressure policy.
 * @license GPL-3.0-only
 */

/** Format bytes into a compact human-readable string. */
function pmssWebCgroupMemoryStatusFormatBytes($bytes, $precision = 1)
{
    $bytes = pmssCustomerNonnegativeFloat($bytes);
    return $bytes === null ? 'n/a' : pmssFormatBytes($bytes, (int) $precision);
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
    $memoryBreakdown = $cgroupAvailable ? pmssWebCgroupMemoryStatusMemoryStatBreakdownRead(
        pmssCustomerCgroupCounterPaths($uid, rtrim($cgroupDir, '/'), 'memory.stat', 'memory', 'memory.stat'), ['anon']
    ) : [];

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
    $v2Memory = pmssWebCgroupMemoryStatusV2MemoryControllerAvailable($cgroupDir);
    if ($stats['oom_kill_events'] === 0 && !$v2Memory) {
        $stats['oom_kill_events'] = pmssWebCgroupMemoryStatusV1OomKillRead($cgroupDir, $uid);
    }
    if ($stats['oom_kill_events'] > 0) {
        $stats['oom_kill_recent'] = $v2Memory || pmssWebCgroupMemoryStatusOomRecent(
            $stats['oom_kill_events'],
            pmssCustomerSerializedArrayFileRead($overrides['oom_status_path'] ?? '../.oomKillStatus'),
            $overrides['now'] ?? time()
        );
    }
    $status = pmssWebCgroupMemoryStatusClassify(array_replace($stats, [
        'memory_current' => $memoryPressureCurrent,
        'usage_percent' => $stats['pressure_usage_percent'],
        'high_percent' => $stats['pressure_high_percent'],
    ]));

    return $stats + [
        'status' => $status,
        'status_color' => ['LOW' => '#81c784', 'MEDIUM' => '#ffb74d', 'HIGH' => '#ef5350', 'THROTTLED' => '#d2691e'][$status] ?? '#b0bec5',
        'message' => !empty($stats['oom_kill_recent'])
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
