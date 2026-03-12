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
        return (int)$override;
    }
    foreach (@file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (strpos($line, 'MemTotal:') === 0) {
            $kb = (int)filter_var($line, FILTER_SANITIZE_NUMBER_INT);
            return (int)round($kb / 1024);
        }
    }
    return 0;
}

/** Return total logical CPU threads. */
function pmssTotalCpuThreads(): int
{
    $override = getenv('PMSS_TOTAL_CPU_THREADS');
    if (is_string($override) && ctype_digit($override)) {
        return (int)$override;
    }
    // Robust check using /proc/cpuinfo
    $cpuinfo = @file_get_contents('/proc/cpuinfo');
    if ($cpuinfo !== false && ($count = substr_count($cpuinfo, 'processor')) > 0) {
        return $count;
    }

    // Fallback to nproc if available
    $nproc = @shell_exec('nproc');
    if ($nproc !== null && ($count = (int)trim($nproc)) > 0) {
        return $count;
    }

    return 0;
}
