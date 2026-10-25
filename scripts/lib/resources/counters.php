<?php
/** Cgroup counter acquisition for resource logs and performance telemetry.
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../resources.php';
require_once __DIR__.'/../systemdSliceProperties.php';
require_once __DIR__.'/v1.php';

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

    return $values + (pmssResourceLogReadMemoryBreakdown($uid, $cgroupRoot) ?? []);
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
    $sample = pmssResourceV1ReadMetrics($uid, $cgroupRoot,
        ['cpu_usage_nsec', 'mem_current', 'pids_current', 'mem_total_rss', 'mem_rss', 'mem_total_cache', 'mem_cache']);
    $values = [];
    foreach (['cpu_nsec' => 'cpu_usage_nsec', 'memory' => 'mem_current', 'tasks' => 'pids_current'] as $key => $field) {
        if (isset($sample[$field])) $values[$key] = $sample[$field];
    }
    // Billing must not persist a partial IO baseline: recovered ops would become a phantom delta.
    // The source label travels with bytes AND ops so a family switch can reseed the baseline (#707).
    if (isset($sample['io_ops_read'], $sample['io_ops_write'])) {
        $values += ['io_read' => $sample['io_bytes_read'], 'io_write' => $sample['io_bytes_write'],
            'io_read_ops' => $sample['io_ops_read'], 'io_write_ops' => $sample['io_ops_write'], 'io_source' => $sample['io_source']];
    }
    // User processes live in child cgroups: prefer hierarchical totals, even when they are zero.
    foreach (['memory_anon' => ['mem_total_rss', 'mem_rss'], 'memory_file' => ['mem_total_cache', 'mem_cache']] as $key => $fields) {
        if (($value = $sample[$fields[0]] ?? $sample[$fields[1]] ?? null) !== null) $values[$key] = $value;
    }
    return $values === [] ? null : $values;
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
        foreach (pmssConfigTextRows($raw, 2, [], 2) as [$field, $value]) {
            if (!isset($memoryFields[$field]) || !ctype_digit((string) $value)) {
                continue;
            }
            $breakdown[$memoryFields[$field]] = (int) $value;
        }

        if (count($breakdown) === count($memoryFields)) { return $breakdown; }
    }

    return null;
}
