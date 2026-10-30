<?php
/**
 * Shared cgroup-v1 source acquisition for billing and performance projections.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../runtime.php';

// UINT64_MAX casts to PHP_INT_MAX (9223372036854775807) in PHP. systemd reports it
// for unavailable slice properties on cgroup v1 (#467). Treat any value at/above this
// sentinel as "absent" so it never poisons the per-user resource log.
const PMSS_RESOURCE_COUNTER_SENTINEL = PHP_INT_MAX;

/** Read one non-negative integer cgroup counter file, rejecting the UINT64_MAX sentinel. */
function pmssResourceLogReadSysfsCounter(string $path): ?int
{
    $value = pmssReadRegularFileInt($path, -1);
    return ($value < 0 || $value >= PMSS_RESOURCE_COUNTER_SENTINEL) ? null : $value;
}

/**
 * Sum the Read/Write rows of a v1 blkio per-device file.
 * Totals must stay below the counter sentinel; an unrepresentable sum is absent.
 */
function pmssResourceLogReadBlkioReadWrite(string $path): ?array
{
    $totals = null;
    foreach (pmssConfigTextLines(pmssReadRegularFileContents($path) ?? '', []) as $line) {
        if (preg_match('/^\S+\s+(Read|Write)\s+([0-9]+)$/', $line, $m) !== 1) continue;
        $value = pmssUnsignedDecimalIntParse($m[2]);
        if ($value === null || $value >= PMSS_RESOURCE_COUNTER_SENTINEL) continue;
        $direction = $m[1] === 'Read' ? 'read' : 'write';
        $totals = $totals ?? ['read' => 0, 'write' => 0];
        // Check before addition: integer overflow becomes a float and can later cast negative.
        if ($value >= PMSS_RESOURCE_COUNTER_SENTINEL - $totals[$direction]) return null;
        $totals[$direction] += $value;
    }

    return $totals;
}

/**
 * Read v1 per-cgroup blkio Read/Write totals, preferring the BFQ accounting file.
 *
 * Under the fleet-default BFQ scheduler (rotational/md hosts) the real per-cgroup I/O lands
 * in blkio.bfq.*; blkio.throttle.* exists but stays all-zero unless an explicit throttle
 * policy is applied (#467/#707). "Whichever is non-null" is WRONG — the throttle file is
 * present-but-zero under BFQ and would win, reproducing the zero-metering bug. Prefer bfq
 * when it carries data; fall back to throttle for non-BFQ hosts (SSD/nvme) where the bfq
 * file is absent. Returns the read source label so the delta path can guard a source switch.
 *
 * @return array{read:int,write:int,source:string}|null
 */
function pmssResourceLogReadBlkioBytesWithSource(string $blkioSliceDir, string $bfqFile = 'blkio.bfq.io_service_bytes', string $throttleFile = 'blkio.throttle.io_service_bytes'): ?array
{
    $bfq = pmssResourceLogReadBlkioReadWrite($blkioSliceDir.$bfqFile);
    if ($bfq !== null && ($bfq['read'] > 0 || $bfq['write'] > 0)) return $bfq + ['source' => 'bfq'];
    $throttle = pmssResourceLogReadBlkioReadWrite($blkioSliceDir.$throttleFile);
    if ($throttle !== null && ($bfq === null || $throttle['read'] > 0 || $throttle['write'] > 0)) return $throttle + ['source' => 'throttle'];
    // Both absent or genuinely zero: keep a stable source label from whichever file exists so
    // an idle user does not thrash io_source (which would force a spurious reseed each sample).
    return $bfq === null ? null : $bfq + ['source' => 'bfq'];
}

/** Read requested v1 key/value counters once, retaining the first valid row per field. */
function pmssResourceLogReadMemoryStatFields(string $path, array $fields): array
{
    $wanted = array_fill_keys($fields, true); $values = [];
    foreach (pmssConfigTextRows(pmssReadRegularFileContents($path) ?? '', 2, [], 2) as [$name, $value]) {
        if (!isset($wanted[$name]) || !ctype_digit((string) $value)) continue;
        // Even an overflowing first numeric row claims the field; later duplicates stay ignored.
        unset($wanted[$name]); $parsed = pmssUnsignedDecimalIntParse($value);
        if ($parsed !== null && $parsed < PMSS_RESOURCE_COUNTER_SENTINEL) $values[$name] = $parsed;
    }
    return $values;
}

/** Retain the single-field reader contract for existing callers. */
function pmssResourceLogReadMemoryStatField(string $path, string $field): ?int { return pmssResourceLogReadMemoryStatFields($path, [$field])[$field] ?? null; }

/**
 * Acquire v1 sources once in telemetry order; billing may request just its non-IO fields.
 * Missing counters are omitted. IO ops always use the selected bytes accounting family.
 */
function pmssResourceV1ReadMetrics(int $uid, ?string $cgroupRoot = null, ?array $wanted = null): array
{
    $root = rtrim($cgroupRoot ?? '/sys/fs/cgroup', '/');
    $slice = '/user.slice/user-'.$uid.'.slice/';
    // Scalar files and key/value files share a source traversal, not consumer-specific readers.
    $sources = [
        'cpuacct/cpuacct.usage' => 'cpu_usage_nsec',
        'cpuacct/cpuacct.stat' => ['cpu_user_ticks' => 'user', 'cpu_system_ticks' => 'system'],
        'cpu/cpu.stat' => ['cpu_nr_periods' => 'nr_periods', 'cpu_nr_throttled' => 'nr_throttled', 'cpu_throttled_nsec' => 'throttled_time'],
        'memory/memory.usage_in_bytes' => 'mem_current',
        'memory/memory.max_usage_in_bytes' => 'mem_peak',
        'memory/memory.limit_in_bytes' => 'mem_limit',
        'memory/memory.failcnt' => 'mem_failcnt',
        'memory/memory.memsw.usage_in_bytes' => 'memsw_current',
        'memory/memory.oom_control' => ['mem_oom_kill' => 'oom_kill'],
        'memory/memory.stat' => array_combine(
            array_map(static function (string $field): string { return 'mem_'.$field; }, $memoryFields = [
                'rss', 'cache', 'rss_huge', 'mapped_file', 'swap', 'shmem', 'dirty', 'writeback',
                'pgfault', 'pgmajfault', 'pgpgin', 'pgpgout',
                'inactive_anon', 'active_anon', 'inactive_file', 'active_file', 'unevictable', 'total_rss', 'total_cache',
            ]), $memoryFields
        ),
        'pids/pids.current' => 'pids_current',
        'pids/pids.events' => ['pids_events_max' => 'max'],
    ];
    $wanted = $wanted === null ? null : array_flip($wanted);
    $metrics = [];
    foreach ($sources as $source => $fields) {
        [$controller, $file] = explode('/', $source, 2);
        $fields = is_string($fields) ? [$fields => ''] : $fields;
        if ($wanted !== null) $fields = array_intersect_key($fields, $wanted);
        if ($fields === []) continue;
        $path = $root.'/'.$controller.$slice.$file;
        $values = reset($fields) === '' ? ['' => pmssResourceLogReadSysfsCounter($path)]
            : pmssResourceLogReadMemoryStatFields($path, array_values($fields));
        foreach ($fields as $metric => $field) {
            if (isset($values[$field])) $metrics[$metric] = $values[$field];
        }
    }
    // Keep partial IO for telemetry; billing enforces its atomic group at projection time.
    $bytes = pmssResourceLogReadBlkioBytesWithSource($root.'/blkio'.$slice);
    if ($bytes !== null) {
        $metrics += ['io_bytes_read' => $bytes['read'], 'io_bytes_write' => $bytes['write'], 'io_source' => $bytes['source']];
        $ops = pmssResourceLogReadBlkioReadWrite($root.'/blkio'.$slice.'blkio.'.$bytes['source'].'.io_serviced');
        if ($ops !== null) $metrics += ['io_ops_read' => $ops['read'], 'io_ops_write' => $ops['write']];
    }
    return $metrics;
}
