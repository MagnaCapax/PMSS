<?php
/** Host-capacity defaults and policy projection for the shared user slice. @license GPL-3.0-only */

/** Render numeric policy once; return the template's own tokens rather than a second sizing schema. */
function pmssSystemdSliceSizing(array $policy, int $totalMiB, int $cpuThreads): array
{
    // MemoryHigh has a 250 MiB floor; MemoryMax cannot exceed 95% of host memory.
    $high = max(250, is_numeric($policy['memoryHighMiB'] ?? null)
        ? (int) $policy['memoryHighMiB'] : (int) floor($totalMiB * 0.10));
    $quota = $policy['cpuQuotaPercent'] ?? null;
    $values = [
        '%%USER_CGROUP_MEMORY_HIGH%%' => (string) $high,
        '%%USER_CGROUP_MEMORY_MAX%%' => (string) min(
            is_numeric($policy['memoryMaxMiB'] ?? null) ? (int) $policy['memoryMaxMiB'] : (int) floor($high * 1.25),
            (int) floor($totalMiB * 0.95)
        ),
    ];
    // All three integer overrides share the same numeric acceptance and cast rule.
    foreach ([
        'CPU_WEIGHT' => ['cpuWeight', 200],
        'IO_WEIGHT' => ['ioWeight', 200],
        // TasksMax counts threads; scale by the larger of CPU threads and memory GiB.
        'TASKS_MAX' => ['tasksMax', max(2048, min(16384, 512 * max($cpuThreads, $totalMiB > 0 ? (int) ceil($totalMiB / 1024) : 0)))],
    ] as $token => [$key, $default]) {
        $values['%%USER_CGROUP_'.$token.'%%'] = (string) (is_numeric($policy[$key] ?? null) ? (int) $policy[$key] : $default);
    }
    // Reserve 15% of detected CPU capacity; retain the six-core detection fallback.
    $values['%%USER_CGROUP_CPU_QUOTA%%'] = is_string($quota) && strtolower($quota) === 'infinity'
        ? 'infinity' : (is_numeric($quota) ? (int) $quota : ($cpuThreads > 0 ? $cpuThreads * 85 : 600)).'%';
    return $values;
}
