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

require_once __DIR__.'/log.php'; // pmssResourceLogRead{SysfsCounter,BlkioReadWrite,MemoryStatField}, PMSS_RESOURCE_COUNTER_SENTINEL
require_once __DIR__.'/../runtime.php'; // pmssReadRegularFile*

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
    pmssMetricSet($m, 'cpu_usage_nsec', pmssResourceLogReadSysfsCounter($root.'/cpuacct'.$slice.'cpuacct.usage'));
    // cpuacct.stat reports user/system in USER_HZ ticks (not ns) — recorded raw.
    pmssMetricSet($m, 'cpu_user_ticks', pmssResourceLogReadMemoryStatField($root.'/cpuacct'.$slice.'cpuacct.stat', 'user'));
    pmssMetricSet($m, 'cpu_system_ticks', pmssResourceLogReadMemoryStatField($root.'/cpuacct'.$slice.'cpuacct.stat', 'system'));
    // CFS throttling (only populated when a CPU quota is set on the slice).
    pmssMetricSet($m, 'cpu_nr_periods', pmssResourceLogReadMemoryStatField($root.'/cpu'.$slice.'cpu.stat', 'nr_periods'));
    pmssMetricSet($m, 'cpu_nr_throttled', pmssResourceLogReadMemoryStatField($root.'/cpu'.$slice.'cpu.stat', 'nr_throttled'));
    pmssMetricSet($m, 'cpu_throttled_nsec', pmssResourceLogReadMemoryStatField($root.'/cpu'.$slice.'cpu.stat', 'throttled_time'));

    // --- Memory (memory controller) ---
    pmssMetricSet($m, 'mem_current', pmssResourceLogReadSysfsCounter($root.'/memory'.$slice.'memory.usage_in_bytes'));
    pmssMetricSet($m, 'mem_peak', pmssResourceLogReadSysfsCounter($root.'/memory'.$slice.'memory.max_usage_in_bytes'));
    pmssMetricSet($m, 'mem_limit', pmssResourceLogReadSysfsCounter($root.'/memory'.$slice.'memory.limit_in_bytes'));
    pmssMetricSet($m, 'mem_failcnt', pmssResourceLogReadSysfsCounter($root.'/memory'.$slice.'memory.failcnt'));
    pmssMetricSet($m, 'memsw_current', pmssResourceLogReadSysfsCounter($root.'/memory'.$slice.'memory.memsw.usage_in_bytes'));
    pmssMetricSet($m, 'mem_oom_kill', pmssResourceLogReadMemoryStatField($root.'/memory'.$slice.'memory.oom_control', 'oom_kill'));
    // Full memory.stat field set (v1 names).
    foreach ([
        'rss', 'cache', 'rss_huge', 'mapped_file', 'swap', 'shmem', 'dirty', 'writeback',
        'pgfault', 'pgmajfault', 'pgpgin', 'pgpgout',
        'inactive_anon', 'active_anon', 'inactive_file', 'active_file', 'unevictable',
    ] as $field) {
        pmssMetricSet($m, 'mem_'.$field, pmssResourceLogReadMemoryStatField($root.'/memory'.$slice.'memory.stat', $field));
    }

    // --- PIDs ---
    pmssMetricSet($m, 'pids_current', pmssResourceLogReadSysfsCounter($root.'/pids'.$slice.'pids.current'));
    pmssMetricSet($m, 'pids_events_max', pmssResourceLogReadMemoryStatField($root.'/pids'.$slice.'pids.events', 'max'));

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

    return $m;
}

/** Set a metric only when the value is a usable non-negative integer. */
function pmssMetricSet(array &$metrics, string $key, ?int $value): void
{
    if ($value !== null) $metrics[$key] = $value;
}

/** Merge a {read,write} pair into <prefix>_read / <prefix>_write when present. */
function pmssMetricMergeReadWrite(array &$metrics, string $prefix, ?array $pair): void
{
    if ($pair === null) return;
    $metrics[$prefix.'_read'] = $pair['read'];
    $metrics[$prefix.'_write'] = $pair['write'];
}
