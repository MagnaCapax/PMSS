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
    if ($override === 'v2' || $override === 'v1') return $override;

    // Strongest signal: cgroup2 mount present in /proc/self/mountinfo
    $mountinfo = @file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($mountinfo as $line) {
        // Fields: ... mountpoint ... - fstype source options
        // Look for " - cgroup2 " which unambiguously indicates unified v2
        if (strpos($line, ' - cgroup2 ') !== false) return 'v2';
    }

    // Kernel exposes controllers file only on v2
    if (is_file('/sys/fs/cgroup/cgroup.controllers')) return 'v2';

    // Kernel cmdline override used by systemd to force v1 on newer Debian
    $cmdline = @file_get_contents('/proc/cmdline');
    if (is_string($cmdline) && strpos($cmdline, 'systemd.unified_cgroup_hierarchy=0') !== false) {
        return 'v1';
    }

    // v1 hint: presence of controller directories under /sys/fs/cgroup/
    $v1hints = glob('/sys/fs/cgroup/*', GLOB_ONLYDIR) ?: [];
    foreach ($v1hints as $d) {
        if (basename((string)$d) !== 'unified') return 'v1';
    }

    return 'unknown';
}
