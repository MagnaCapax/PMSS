<?php
/** Cgroup counter acquisition for resource logs and performance telemetry.
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../resources.php';
require_once __DIR__.'/../systemdSliceProperties.php';

// UINT64_MAX casts to PHP_INT_MAX (9223372036854775807) in PHP. systemd reports it
// for unavailable slice properties on cgroup v1 (#467). Treat any value at/above this
// sentinel as "absent" so it never poisons the per-user resource log.
const PMSS_RESOURCE_COUNTER_SENTINEL = PHP_INT_MAX;

/**
 * Read per-user slice counters, selecting the source by active cgroup hierarchy.
 *
 * On cgroup v1 (the Debian 12 fleet default, systemd.unified_cgroup_hierarchy=0),
 * systemd does not populate IO* slice properties — it returns UINT64_MAX, which the
 * delta logic silently floors to 0 (#467). On v1 we therefore read the real counters
 * straight from the per-controller sysfs tree (the same tree directApply.php already
 * writes to). On v2/unknown the systemd path is unchanged. Output keys are identical
 * across both paths so the resource log line format is untouched.
 *
 * @param string|null $cgroupRoot sysfs cgroup root, injectable for tests (default /sys/fs/cgroup).
 */
function pmssResourceLogReadCounters(int $uid, ?string $cgroupRoot = null): ?array
{
    if (pmssCgroupMode() === 'v1') {
        return pmssResourceLogReadCountersV1($uid, $cgroupRoot);
    }

    $values = pmssReadSystemdIntProperties(
        sprintf('user-%d.slice', $uid),
        [
            'IOReadBytes' => 'io_read',
            'IOWriteBytes' => 'io_write',
            'IOReadOperations' => 'io_read_ops',
            'IOWriteOperations' => 'io_write_ops',
            'CPUUsageNSec' => 'cpu_nsec',
            'MemoryCurrent' => 'memory',
            'TasksCurrent' => 'tasks',
        ],
        [
            'IOReadOperations' => 0,
            'IOWriteOperations' => 0,
        ]
    );
    if (!is_array($values)) return null;

    $memoryBreakdown = pmssResourceLogReadMemoryBreakdown($uid, $cgroupRoot);
    return is_array($memoryBreakdown) ? $values + $memoryBreakdown : $values;
}

/**
 * Read per-user slice counters directly from the cgroup v1 sysfs tree.
 *
 * Every field is read independently and OMITTED when its source file is missing,
 * unreadable, or non-numeric (graceful absence — downstream `?? 0` applies). A slice
 * with no readable source at all returns null, matching the systemd path's contract.
 * Output keys match the v2 path exactly.
 */
function pmssResourceLogReadCountersV1(int $uid, ?string $cgroupRoot = null): ?array
{
    $root = rtrim($cgroupRoot ?? '/sys/fs/cgroup', '/');
    $slice = '/user.slice/user-'.$uid.'.slice/';
    $values = [];

    if (($cpu = pmssResourceLogReadSysfsCounter($root.'/cpuacct'.$slice.'cpuacct.usage')) !== null) $values['cpu_nsec'] = $cpu;
    if (($mem = pmssResourceLogReadSysfsCounter($root.'/memory'.$slice.'memory.usage_in_bytes')) !== null) $values['memory'] = $mem;
    if (($tasks = pmssResourceLogReadSysfsCounter($root.'/pids'.$slice.'pids.current')) !== null) $values['tasks'] = $tasks;

    // Per-cgroup I/O accounting: prefer the BFQ file (real data under the fleet-default BFQ
    // scheduler on rotational/md hosts), fall back to throttle for non-BFQ hosts. Read ops
    // from the SAME accounting family the bytes came from, and record io_source so the delta
    // path can reseed the baseline on a source switch, avoiding a phantom first delta (#707).
    $blkioSlice = $root.'/blkio'.$slice;
    $bytes = pmssResourceLogReadBlkioBytesWithSource($blkioSlice, 'blkio.bfq.io_service_bytes', 'blkio.throttle.io_service_bytes');
    if ($bytes !== null) {
        // ATOMIC io group: bytes, ops, AND io_source are recorded together or not at all. If the
        // ops file is transiently unreadable, omit the WHOLE io sample rather than store a partial
        // baseline (io_source set + io_*_ops=0). A partial baseline would let the ops cumulative
        // flow as an unguarded phantom delta on recovery — the source is unchanged, so the §0
        // reseed guard (keyed on io_source) would NOT fire — risking a spurious IOPS throttle.
        // Safe: on a BFQ host both bfq.* files are exposed together (CONFIG_BFQ_CGROUP_DEBUG), so
        // "bytes present, ops absent" is only a transient read failure, never a steady state.
        $opsFile = $bytes['source'] === 'bfq' ? 'blkio.bfq.io_serviced' : 'blkio.throttle.io_serviced';
        $ops = pmssResourceLogReadBlkioReadWrite($blkioSlice.$opsFile);
        if ($ops !== null) {
            $values += ['io_read' => $bytes['read'], 'io_write' => $bytes['write'],
                'io_read_ops' => $ops['read'], 'io_write_ops' => $ops['write'], 'io_source' => $bytes['source']];
        }
    }

    // The user slice owns child cgroups, so prefer hierarchical totals over its often-zero local fields.
    $memoryStat = $root.'/memory'.$slice.'memory.stat';
    foreach (['memory_anon' => ['total_rss', 'rss'], 'memory_file' => ['total_cache', 'cache']] as $key => $fields) {
        $value = pmssResourceLogReadMemoryStatField($memoryStat, $fields[0])
            ?? pmssResourceLogReadMemoryStatField($memoryStat, $fields[1]);
        if ($value !== null) $values[$key] = $value;
    }

    return $values === [] ? null : $values;
}

/** Read one non-negative integer cgroup counter file, rejecting the UINT64_MAX sentinel. */
function pmssResourceLogReadSysfsCounter(string $path): ?int
{
    $raw = pmssReadRegularFileTrimmed($path);
    if ($raw === null || !ctype_digit($raw)) return null;
    $value = (int) $raw;
    return ($value < 0 || $value >= PMSS_RESOURCE_COUNTER_SENTINEL) ? null : $value;
}

/**
 * Sum the Read/Write rows of a v1 blkio per-device file.
 * Totals must stay below the counter sentinel; an unrepresentable sum is absent.
 */
function pmssResourceLogReadBlkioReadWrite(string $path): ?array
{
    $raw = pmssReadRegularFileContents($path);
    if ($raw === null || trim($raw) === '') return null;

    $totals = ['read' => 0, 'write' => 0];
    $matched = false;
    foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
        if (preg_match('/^\S+\s+(Read|Write)\s+([0-9]+)$/', trim((string) $line), $m) !== 1) continue;
        $value = (int) $m[2];
        if ($value < 0 || $value >= PMSS_RESOURCE_COUNTER_SENTINEL) continue;
        $direction = $m[1] === 'Read' ? 'read' : 'write';
        // Check before addition: integer overflow becomes a float and can later cast negative.
        if ($value >= PMSS_RESOURCE_COUNTER_SENTINEL - $totals[$direction]) return null;
        $totals[$direction] += $value;
        $matched = true;
    }

    return $matched ? $totals : null;
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
function pmssResourceLogReadBlkioBytesWithSource(string $blkioSliceDir, string $bfqFile, string $throttleFile): ?array
{
    $bfq = pmssResourceLogReadBlkioReadWrite($blkioSliceDir.$bfqFile);
    if ($bfq !== null && ($bfq['read'] > 0 || $bfq['write'] > 0)) return $bfq + ['source' => 'bfq'];
    $throttle = pmssResourceLogReadBlkioReadWrite($blkioSliceDir.$throttleFile);
    if ($throttle !== null && ($throttle['read'] > 0 || $throttle['write'] > 0)) return $throttle + ['source' => 'throttle'];
    // Both absent or genuinely zero: keep a stable source label from whichever file exists so
    // an idle user does not thrash io_source (which would force a spurious reseed each sample).
    if ($bfq !== null) return $bfq + ['source' => 'bfq'];
    if ($throttle !== null) return $throttle + ['source' => 'throttle'];
    return null;
}

/** Read one named field from a v1 memory.stat file. */
function pmssResourceLogReadMemoryStatField(string $path, string $field): ?int
{
    $raw = pmssReadRegularFileContents($path);
    if ($raw === null || trim($raw) === '') return null;

    foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
        [$name, $value] = array_pad(pmssConfigLineColumns((string) $line, 0, [], 2), 2, null);
        if ($name !== $field || !ctype_digit((string) $value)) continue;
        $parsed = (int) $value;
        return ($parsed < 0 || $parsed >= PMSS_RESOURCE_COUNTER_SENTINEL) ? null : $parsed;
    }

    return null;
}

/**
 * Read cgroup v2 memory.stat counters for the given user slice.
 */
function pmssResourceLogReadMemoryBreakdown(int $uid, ?string $cgroupRoot = null): ?array
{
    $root = rtrim($cgroupRoot ?? '/sys/fs/cgroup', '/');
    $memoryFields = pmssResourceMemoryBreakdownFieldMap();
    $paths = [
        $root.'/user.slice/user-'.$uid.'.slice/memory.stat',
        $root.'/unified/user.slice/user-'.$uid.'.slice/memory.stat',
    ];

    foreach ($paths as $path) {
        if (!is_string($raw = @file_get_contents($path)) || trim($raw) === '') {
            continue;
        }

        $breakdown = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            [$field, $value] = array_pad(pmssConfigLineColumns($line, 0, [], 2), 2, null);
            if (!isset($memoryFields[$field]) || !ctype_digit((string) $value)) {
                continue;
            }
            $breakdown[$memoryFields[$field]] = (int) $value;
        }

        if (count($breakdown) === count($memoryFields)) { return $breakdown; }
    }

    return null;
}
