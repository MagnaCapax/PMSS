<?php
/**
 * Cgroup detection helpers for system preparation.
 *
 * Kept in a dedicated module to keep update/systemPrep.php readable while
 * preserving the public function surface.
 *
 * @license GPL-3.0-only
 */

/**
 * Detect cgroup mode: 'v2', 'v1', or 'unknown'.
 */
function pmssCgroupMode(): string
{
    $override = getenv('PMSS_CGROUP_MODE');
    if ($override === 'v1' || $override === 'v2') {
        return $override;
    }

    // Strongest signal: cgroup2 mount present in /proc/self/mountinfo.
    // Also treat cgroup.controllers as a definitive v2 signal.
    if (is_file('/sys/fs/cgroup/cgroup.controllers')
        || strpos((string) @file_get_contents('/proc/self/mountinfo'), ' - cgroup2 ') !== false) {
        return 'v2';
    }

    // Kernel cmdline override used by systemd to force v1 on newer Debian
    if (strpos((string) @file_get_contents('/proc/cmdline'), 'systemd.unified_cgroup_hierarchy=0') !== false) {
        return 'v1';
    }

    // v1 hint: presence of controller directories under /sys/fs/cgroup/
    foreach (glob('/sys/fs/cgroup/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (basename((string) $dir) !== 'unified') {
            return 'v1';
        }
    }

    return 'unknown';
}
