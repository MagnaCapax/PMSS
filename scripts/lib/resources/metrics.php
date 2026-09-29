<?php
/**
 * Comprehensive per-user performance metric collection (cgroup v1).
 *
 * Purpose: capture every available per-user cgroup performance metric as a
 * self-describing time-series, additively, WITHOUT touching the billing-critical
 * resource log (scripts/lib/resources/log.php) or its format. Output is one JSON
 * object per cycle per user (JSONL); new metrics are added by adding a read — old
 * readers ignore unknown keys, missing keys are simply absent (graceful absence).
 *
 * The fleet runs cgroup v1 legacy (systemd.unified_cgroup_hierarchy=0). This
 * collector reads the v1 per-controller sysfs trees only. Per-cgroup PSI is a
 * cgroup-v2-only kernel feature and is therefore NOT collected — it is not a
 * pending decision, it does not exist on v1.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/counters.php';

/**
 * Collect every available per-user performance metric for one UID from the
 * cgroup v1 sysfs tree.
 *
 * Every value is read independently and OMITTED when its source is missing/
 * unreadable/non-numeric. Returns [] when nothing at all is readable (caller skips).
 *
 * @param string|null $cgroupRoot sysfs root, injectable for tests (default /sys/fs/cgroup).
 * @return array<string,int>
 */
function pmssUserMetricsCollect(int $uid, ?string $cgroupRoot = null): array
{
    $root = rtrim($cgroupRoot ?? '/sys/fs/cgroup', '/');
    $slice = '/user.slice/user-'.$uid.'.slice/';
    $m = [];

    // --- CPU (cpuacct + cpu controllers) ---
    $m['cpu_usage_nsec'] = pmssResourceLogReadSysfsCounter($root.'/cpuacct'.$slice.'cpuacct.usage');
    // cpuacct.stat reports user/system in USER_HZ ticks (not ns) — recorded raw.
    $cpuTicks = pmssResourceLogReadMemoryStatFields($root.'/cpuacct'.$slice.'cpuacct.stat', ['user', 'system']);
    foreach (['cpu_user_ticks' => 'user', 'cpu_system_ticks' => 'system'] as $key => $field) $m[$key] = $cpuTicks[$field] ?? null;
    // CFS throttling (only populated when a CPU quota is set on the slice).
    $cpuStat = pmssResourceLogReadMemoryStatFields($root.'/cpu'.$slice.'cpu.stat', ['nr_periods', 'nr_throttled', 'throttled_time']);
    foreach (['cpu_nr_periods' => 'nr_periods', 'cpu_nr_throttled' => 'nr_throttled',
        'cpu_throttled_nsec' => 'throttled_time'] as $key => $field) $m[$key] = $cpuStat[$field] ?? null;

    // --- Memory (memory controller) ---
    foreach (['mem_current' => 'memory.usage_in_bytes', 'mem_peak' => 'memory.max_usage_in_bytes',
        'mem_limit' => 'memory.limit_in_bytes', 'mem_failcnt' => 'memory.failcnt',
        'memsw_current' => 'memory.memsw.usage_in_bytes'] as $key => $file) {
        $m[$key] = pmssResourceLogReadSysfsCounter($root.'/memory'.$slice.$file);
    }
    $m['mem_oom_kill'] = pmssResourceLogReadMemoryStatField($root.'/memory'.$slice.'memory.oom_control', 'oom_kill');
    // Full memory.stat field set (v1 names).
    $memoryFields = [
        'rss', 'cache', 'rss_huge', 'mapped_file', 'swap', 'shmem', 'dirty', 'writeback',
        'pgfault', 'pgmajfault', 'pgpgin', 'pgpgout',
        'inactive_anon', 'active_anon', 'inactive_file', 'active_file', 'unevictable',
    ];
    $memoryStat = pmssResourceLogReadMemoryStatFields($root.'/memory'.$slice.'memory.stat', $memoryFields);
    foreach ($memoryFields as $field) $m['mem_'.$field] = $memoryStat[$field] ?? null;

    // --- PIDs ---
    $m['pids_current'] = pmssResourceLogReadSysfsCounter($root.'/pids'.$slice.'pids.current');
    $m['pids_events_max'] = pmssResourceLogReadMemoryStatField($root.'/pids'.$slice.'pids.events', 'max');

    // --- Block IO (blkio controller) ---
    // Prefer BFQ per-cgroup accounting (fleet-default scheduler on rotational/md hosts); fall
    // back to throttle on non-BFQ hosts. Match ops to the same family the bytes came from (#707).
    // (Raw cumulative telemetry — no delta/enforcement, so no source-reseed guard needed here.)
    $ioBytes = pmssResourceLogReadBlkioBytesWithSource($root.'/blkio'.$slice, 'blkio.bfq.io_service_bytes', 'blkio.throttle.io_service_bytes');
    pmssMetricMergeReadWrite($m, 'io_bytes', $ioBytes);
    if ($ioBytes !== null) {
        $opsFile = $ioBytes['source'] === 'bfq' ? 'blkio.bfq.io_serviced' : 'blkio.throttle.io_serviced';
        pmssMetricMergeReadWrite($m, 'io_ops', pmssResourceLogReadBlkioReadWrite($root.'/blkio'.$slice.$opsFile));
    }
    // The CFQ-era blkio.io_service_time / io_wait_time / io_queued files do not exist under the
    // BFQ or throttle policies on any current host (blk-mq); their reads were dead. Removed (#707).

    // Omit unavailable readings once, retaining zero counters and insertion order.
    return array_filter($m, static function (?int $value): bool { return $value !== null; });
}

/** Merge a {read,write} pair into <prefix>_read / <prefix>_write when present. */
function pmssMetricMergeReadWrite(array &$metrics, string $prefix, ?array $pair): void
{
    if ($pair === null) return;
    $metrics[$prefix.'_read'] = $pair['read'];
    $metrics[$prefix.'_write'] = $pair['write'];
}
