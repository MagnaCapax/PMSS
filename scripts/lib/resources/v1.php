<?php
/**
 * Shared cgroup-v1 source acquisition for billing and performance projections.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/../cgroup/policy.php';

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

/** Normalise a textual major:minor ("008:000") to its numeric form ("8:0"), or null when malformed. */
function pmssResourceLogDeviceKey(string $device): ?string
{
    if (preg_match('/\A([0-9]+):([0-9]+)\z/', trim($device), $m) !== 1) return null;
    $major = pmssUnsignedDecimalIntParse($m[1]); $minor = pmssUnsignedDecimalIntParse($m[2]);
    return $major === null || $minor === null ? null : $major.':'.$minor;
}

/**
 * major:minor keys of the /home device and the whole disks under it (#1297), or null when unresolved.
 *
 * Throttle rows sit on the device the I/O was submitted to (the /home md or dm device, where the
 * read-IOPS cap is written); BFQ rows sit on the whole disks that run the scheduler. Intermediate
 * stacked devices are left out so a row is never counted twice. The mount source comes from the
 * same resolver as the cap. Null keeps the all-device sum: unknown topology is never "no /home I/O".
 * BFQ rows are per whole disk, so other partitions on the /home disks still count.
 *
 * @return array<string,true>|null
 */
function pmssResourceLogHomeDeviceKeys(?callable $mountRunner = null, string $sysClassBlock = '/sys/class/block'): ?array
{
    static $cached = [];
    $useCache = $mountRunner === null && $sysClassBlock === '/sys/class/block';
    if ($useCache && array_key_exists('keys', $cached)) return $cached['keys'];
    $root = rtrim($sysClassBlock, '/'); $keys = null;
    $source = pmssCgroupPolicyMountSourceResolve('/home', $mountRunner);
    if ($source !== '' && !pmssFilesystemPathHasNulByte($source.$root) && strpos($source, '/dev/') === 0) {
        if (strpos($source, '/dev/mapper/') === 0) $source = (string) realpath($source);
        $top = basename($source);
        $topology = pmssBlockDeviceLeaves($top, $root, static function (string $pattern) { return @glob($pattern); });
        if ($topology['complete'] && $topology['leaves'] !== []) {
            $keys = [];
            foreach (array_unique(array_merge([$top], $topology['leaves'])) as $name) {
                $key = pmssResourceLogDeviceKey((string) pmssReadRegularFileContents($root.'/'.$name.'/dev'));
                if ($key === null) { $keys = null; break; }
                $keys[$key] = true;
            }
        }
    }
    if ($useCache) $cached['keys'] = $keys;
    return $keys;
}

/**
 * Sum the Read/Write rows of a v1 blkio per-device file.
 * Totals must stay below the counter sentinel; an unrepresentable sum is absent.
 * With $devices (major:minor => true), only rows of those devices count (#1297).
 */
function pmssResourceLogReadBlkioReadWrite(string $path, ?array $devices = null): ?array
{
    $totals = null;
    foreach (pmssTextLines(pmssReadRegularFileContents($path) ?? '') as $line) {
        // Require major:minor devices; reject NULs before trim() can hide corruption.
        if (pmssFilesystemPathHasNulByte($line)
            || preg_match('/^([0-9]+:[0-9]+)\s+(Read|Write)\s+([0-9]+)$/', trim($line), $m) !== 1) continue;
        if ($devices !== null && !isset($devices[pmssResourceLogDeviceKey($m[1]) ?? ''])) continue;
        $value = pmssUnsignedDecimalIntParse($m[3]);
        if ($value === null || $value >= PMSS_RESOURCE_COUNTER_SENTINEL) continue;
        $direction = $m[2] === 'Read' ? 'read' : 'write';
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
 * file is absent. Recursive counters include processes in nested systemd user services.
 * Returns the read source label so the delta path can guard a source switch.
 *
 * @return array{read:int,write:int,source:string}|null
 */
function pmssResourceLogReadBlkioBytesWithSource(string $blkioSliceDir, string $bfqFile = 'blkio.bfq.io_service_bytes_recursive', string $throttleFile = 'blkio.throttle.io_service_bytes_recursive', ?array $devices = null): ?array
{
    $bfq = pmssResourceLogReadBlkioReadWrite($blkioSliceDir.$bfqFile, $devices);
    if ($bfq !== null && ($bfq['read'] > 0 || $bfq['write'] > 0)) return $bfq + ['source' => 'bfq'];
    $throttle = pmssResourceLogReadBlkioReadWrite($blkioSliceDir.$throttleFile, $devices);
    if ($throttle !== null && ($bfq === null || $throttle['read'] > 0 || $throttle['write'] > 0)) return $throttle + ['source' => 'throttle'];
    // Both absent or genuinely zero: keep a stable source label from whichever file exists so
    // an idle user does not thrash io_source (which would force a spurious reseed each sample).
    return $bfq === null ? null : $bfq + ['source' => 'bfq'];
}

/** Read requested v1 key/value counters once, retaining the first valid row per field. */
function pmssResourceLogReadMemoryStatFields(string $path, array $fields): array
{
    $wanted = array_fill_keys($fields, true); $values = [];
    foreach (pmssTextLines(pmssReadRegularFileContents($path) ?? '') as $line) {
        // Keep malformed rows from claiming a field ahead of a later valid duplicate.
        if (pmssFilesystemPathHasNulByte($line) || ($columns = pmssConfigLineColumns($line, 2, [], 2)) === []) continue;
        [$name, $value] = $columns;
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
 * Missing counters are omitted. Each direction's IO ops use its bytes accounting family.
 * $ioDevices (major:minor => true) limits IO to those devices and marks io_scope (#1297).
 */
function pmssResourceV1ReadMetrics(int $uid, ?string $cgroupRoot = null, ?array $wanted = null, ?array $ioDevices = null): array
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
    $bytes = pmssResourceLogReadBlkioBytesWithSource($root.'/blkio'.$slice, 'blkio.bfq.io_service_bytes_recursive', 'blkio.throttle.io_service_bytes_recursive', $ioDevices);
    if ($bytes !== null) {
        $ops = pmssResourceLogReadBlkioReadWrite($root.'/blkio'.$slice.'blkio.'.$bytes['source'].'.io_serviced_recursive', $ioDevices);
        // BFQ can account reads but miss writes on md hosts (#1296). Keep reads and use
        // a complete throttle write pair; a distinct source makes either transition reseed.
        if ($bytes['source'] === 'bfq' && $bytes['write'] === 0 && $ops !== null && $ops['write'] === 0) {
            $writeBytes = pmssResourceLogReadBlkioReadWrite($root.'/blkio'.$slice.'blkio.throttle.io_service_bytes_recursive', $ioDevices);
            $writeOps = pmssResourceLogReadBlkioReadWrite($root.'/blkio'.$slice.'blkio.throttle.io_serviced_recursive', $ioDevices);
            if ($writeBytes !== null && $writeBytes['write'] > 0 && $writeOps !== null) {
                $bytes['write'] = $writeBytes['write'];
                $ops['write'] = $writeOps['write'];
                $bytes['source'] = 'bfq-read-throttle-write';
            }
        }
        $metrics += ['io_bytes_read' => $bytes['read'], 'io_bytes_write' => $bytes['write'], 'io_source' => $bytes['source'],
            'io_scope' => $ioDevices === null ? 'recursive' : 'home-recursive'];
        if ($ops !== null) $metrics += ['io_ops_read' => $ops['read'], 'io_ops_write' => $ops['write']];
    }
    return $metrics;
}
