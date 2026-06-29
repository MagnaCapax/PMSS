<?php
/**
 * Comprehensive per-user performance metric collection.
 *
 * Purpose: capture EVERY available per-user cgroup/slice performance metric as a
 * self-describing time-series, additively, WITHOUT touching the billing-critical
 * resource log (scripts/lib/resources/log.php) or its format. Output is one JSON
 * object per cycle per user (JSONL) so new metrics are added by adding a read —
 * old readers ignore unknown keys, missing keys are simply absent (graceful absence).
 *
 * Hierarchy: the Debian 12 fleet runs cgroup v1 legacy (systemd.unified_cgroup_hierarchy=0).
 * v1 reads come from per-controller sysfs trees; v2-only metrics (per-cgroup PSI,
 * io.stat discard, memory.events) are emitted only when the host is actually v2.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/log.php'; // pmssResourceLogRead{SysfsCounter,BlkioReadWrite,MemoryStatField}, PMSS_RESOURCE_COUNTER_SENTINEL
require_once __DIR__.'/../runtime.php'; // pmssCgroupMode, pmssReadRegularFile*

/**
 * Collect every available per-user performance metric for one UID.
 *
 * Every value is read independently and OMITTED when its source is missing/unreadable/
 * non-numeric. Returns [] when nothing at all is readable (caller skips the user).
 *
 * @param string|null $mode      cgroup hierarchy ('v1'/'v2'); defaults to pmssCgroupMode().
 * @param string|null $cgroupRoot sysfs root, injectable for tests (default /sys/fs/cgroup).
 * @return array<string,int>
 */
function pmssUserMetricsCollect(int $uid, ?string $mode = null, ?string $cgroupRoot = null): array
{
    $mode = $mode ?? pmssCgroupMode();
    $root = rtrim($cgroupRoot ?? '/sys/fs/cgroup', '/');
    return $mode === 'v2'
        ? pmssUserMetricsCollectV2($uid, $root)
        : pmssUserMetricsCollectV1($uid, $root);
}

/** Collect the full cgroup v1 per-user metric set. */
function pmssUserMetricsCollectV1(int $uid, string $root): array
{
    $slice = '/user.slice/user-'.$uid.'.slice/';
    $m = [];

    // --- CPU (cpuacct + cpu controllers) ---
    pmssMetricSet($m, 'cpu_usage_nsec', pmssResourceLogReadSysfsCounter($root.'/cpuacct'.$slice.'cpuacct.usage'));
    // cpuacct.stat reports user/system in USER_HZ ticks (not ns) — recorded raw, unit noted in docs.
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
    pmssMetricMergeReadWrite($m, 'io_bytes', pmssResourceLogReadBlkioReadWrite($root.'/blkio'.$slice.'blkio.throttle.io_service_bytes'));
    pmssMetricMergeReadWrite($m, 'io_ops', pmssResourceLogReadBlkioReadWrite($root.'/blkio'.$slice.'blkio.throttle.io_serviced'));
    pmssMetricMergeReadWrite($m, 'io_service_time_ns', pmssResourceLogReadBlkioReadWrite($root.'/blkio'.$slice.'blkio.io_service_time'));
    pmssMetricMergeReadWrite($m, 'io_wait_time_ns', pmssResourceLogReadBlkioReadWrite($root.'/blkio'.$slice.'blkio.io_wait_time'));
    pmssMetricMergeReadWrite($m, 'io_queued', pmssResourceLogReadBlkioReadWrite($root.'/blkio'.$slice.'blkio.io_queued'));

    return $m;
}

/** Collect the full cgroup v2 per-user metric set (unified hierarchy). */
function pmssUserMetricsCollectV2(int $uid, string $root): array
{
    $base = $root.'/user.slice/user-'.$uid.'.slice';
    if (!is_dir($base) && is_dir($root.'/unified/user.slice/user-'.$uid.'.slice')) {
        $base = $root.'/unified/user.slice/user-'.$uid.'.slice';
    }
    $m = [];

    // --- CPU (cpu.stat: microseconds) ---
    pmssMetricSet($m, 'cpu_usage_usec', pmssResourceLogReadMemoryStatField($base.'/cpu.stat', 'usage_usec'));
    pmssMetricSet($m, 'cpu_user_usec', pmssResourceLogReadMemoryStatField($base.'/cpu.stat', 'user_usec'));
    pmssMetricSet($m, 'cpu_system_usec', pmssResourceLogReadMemoryStatField($base.'/cpu.stat', 'system_usec'));
    pmssMetricSet($m, 'cpu_nr_periods', pmssResourceLogReadMemoryStatField($base.'/cpu.stat', 'nr_periods'));
    pmssMetricSet($m, 'cpu_nr_throttled', pmssResourceLogReadMemoryStatField($base.'/cpu.stat', 'nr_throttled'));
    pmssMetricSet($m, 'cpu_throttled_usec', pmssResourceLogReadMemoryStatField($base.'/cpu.stat', 'throttled_usec'));

    // --- Memory (v2 names) ---
    pmssMetricSet($m, 'mem_current', pmssResourceLogReadSysfsCounter($base.'/memory.current'));
    pmssMetricSet($m, 'mem_peak', pmssResourceLogReadSysfsCounter($base.'/memory.peak'));
    pmssMetricSet($m, 'mem_swap_current', pmssResourceLogReadSysfsCounter($base.'/memory.swap.current'));
    foreach (['low', 'high', 'max', 'oom', 'oom_kill'] as $ev) {
        pmssMetricSet($m, 'mem_events_'.$ev, pmssResourceLogReadMemoryStatField($base.'/memory.events', $ev));
    }
    foreach ([
        'anon', 'file', 'kernel_stack', 'slab', 'sock', 'shmem', 'file_mapped', 'file_dirty',
        'file_writeback', 'anon_thp', 'inactive_anon', 'active_anon', 'inactive_file', 'active_file',
        'unevictable', 'pgfault', 'pgmajfault', 'workingset_refault', 'pgscan', 'pgsteal',
    ] as $field) {
        pmssMetricSet($m, 'mem_'.$field, pmssResourceLogReadMemoryStatField($base.'/memory.stat', $field));
    }

    // --- PIDs ---
    pmssMetricSet($m, 'pids_current', pmssResourceLogReadSysfsCounter($base.'/pids.current'));
    pmssMetricSet($m, 'pids_events_max', pmssResourceLogReadMemoryStatField($base.'/pids.events', 'max'));

    // --- IO (io.stat: rbytes/wbytes/rios/wios/dbytes/dios, summed across devices) ---
    $io = pmssUserMetricsParseV2IoStat($base.'/io.stat');
    foreach ($io as $k => $v) pmssMetricSet($m, 'io_'.$k, $v);

    // --- Per-cgroup PSI (v2-ONLY) ---
    foreach (['cpu', 'io', 'memory'] as $res) {
        $psi = pmssUserMetricsParsePsi($base.'/'.$res.'.pressure');
        foreach ($psi as $k => $v) pmssMetricSet($m, 'psi_'.$res.'_'.$k, $v);
    }

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

/** Sum cgroup v2 io.stat counters (rbytes/wbytes/rios/wios/dbytes/dios) across all devices. */
function pmssUserMetricsParseV2IoStat(string $path): array
{
    $raw = pmssReadRegularFileContents($path);
    if ($raw === null || trim($raw) === '') return [];

    $keys = ['rbytes', 'wbytes', 'rios', 'wios', 'dbytes', 'dios'];
    $totals = [];
    $seen = false;
    foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
        foreach ($keys as $k) {
            if (preg_match('/\b'.$k.'=([0-9]+)/', (string) $line, $mm) !== 1) continue;
            $value = (int) $mm[1];
            if ($value < 0 || $value >= PMSS_RESOURCE_COUNTER_SENTINEL) continue;
            $totals[$k] = ($totals[$k] ?? 0) + $value;
            $seen = true;
        }
    }
    return $seen ? $totals : [];
}

/**
 * Parse a PSI pressure file into some_avg10/60/300+total and full_avg10/60/300+total.
 * avg* are percentages ×100 (kept as ints, e.g. 4.23 -> 423); total is microseconds.
 */
function pmssUserMetricsParsePsi(string $path): array
{
    $raw = pmssReadRegularFileContents($path);
    if ($raw === null || trim($raw) === '') return [];

    $out = [];
    foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
        if (preg_match('/^(some|full)\s/', trim((string) $line), $mm) !== 1) continue;
        $scope = $mm[1];
        foreach (['avg10', 'avg60', 'avg300'] as $field) {
            if (preg_match('/\b'.$field.'=([0-9.]+)/', $line, $f) === 1) $out[$scope.'_'.$field] = (int) round(((float) $f[1]) * 100);
        }
        if (preg_match('/\btotal=([0-9]+)/', $line, $t) === 1) $out[$scope.'_total'] = (int) $t[1];
    }
    return $out;
}
