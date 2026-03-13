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
    if (is_string($override = getenv('PMSS_TOTAL_MEM_MIB')) && ctype_digit($override)) {
        return (int) $override;
    }

    if (preg_match('/^MemTotal:\s+([0-9]+)/m', (string) @file_get_contents('/proc/meminfo'), $matches) === 1) {
        return (int) round(((int) $matches[1]) / 1024);
    }

    return 0;
}

/** Return total logical CPU threads. */
function pmssTotalCpuThreads(): int
{
    if (is_string($override = getenv('PMSS_TOTAL_CPU_THREADS')) && ctype_digit($override)) {
        return (int) $override;
    }

    // Robust check using /proc/cpuinfo
    $count = substr_count((string) @file_get_contents('/proc/cpuinfo'), 'processor');
    // Fallback to nproc if available
    return $count > 0 ? $count : max(0, (int) trim((string) @shell_exec('nproc')));
}
