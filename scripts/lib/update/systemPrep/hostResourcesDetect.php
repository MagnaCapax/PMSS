<?php
/**
 * Host resource detection helpers for system preparation.
 *
 * These helpers are used to compute safe defaults for cgroup policies.
 *
 * @license GPL-3.0-only
 */

/** Return total system memory in MiB (rounded). */
function pmssTotalMemMiB(): int
{
    $override = getenv('PMSS_TOTAL_MEM_MIB');
    if (is_string($override) && ctype_digit($override)) {
        return (int) $override;
    }

    $meminfo = @file_get_contents('/proc/meminfo');
    if (is_string($meminfo) && preg_match('/^MemTotal:\s+([0-9]+)/m', $meminfo, $matches)) {
        return (int) round(((int) $matches[1]) / 1024);
    }

    return 0;
}

/** Return total logical CPU threads. */
function pmssTotalCpuThreads(): int
{
    $override = getenv('PMSS_TOTAL_CPU_THREADS');
    if (is_string($override) && ctype_digit($override)) {
        return (int) $override;
    }

    // Robust check using /proc/cpuinfo
    $cpuinfo = @file_get_contents('/proc/cpuinfo');
    $count = is_string($cpuinfo) ? substr_count($cpuinfo, 'processor') : 0;
    if ($count < 1) {
        // Fallback to nproc if available
        $count = (int) trim((string) @shell_exec('nproc'));
    }
    return $count > 0 ? $count : 0;
}
