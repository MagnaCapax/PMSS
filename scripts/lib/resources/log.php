<?php
/**
 * Helpers for per-user resource metering via systemd slice accounting.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../userLifecycle.php';
require_once __DIR__.'/../systemdSliceProperties.php';
require_once __DIR__.'/../lighttpd/userFileWrite.php';
require_once __DIR__.'/../resources.php';
require_once __DIR__.'/../user/userFilesystem.php';

const PMSS_RESOURCE_LOG_MAX_INTERVAL_IO_BYTES = 1125899906842624; // 1 PiB per sample.
const PMSS_RESOURCE_LOG_MAX_INTERVAL_IO_OPS = 1000000000; // 3.3M IOPS over the normal 5m cadence.

/**
 * Resolve a validated managed username to its UID.
 */
function pmssResourceLogLookupManagedUid(string $user): ?int
{
    if (!pmssResourceUserIsValid($user)) {
        return null;
    }
    if (($info = pmssUserAccountLookup($user)) !== null) {
        $uid = pmssPasswdEntryPositiveUid($info);
        if ($uid !== null) return $uid;
    }

    $uid = trim((string) @shell_exec('id -u '.escapeshellarg($user).' 2>/dev/null'));
    return ctype_digit($uid) ? (int) $uid : null;
}

/** Return managed resource-account users keyed by validated UID. */
function pmssResourceLogManagedUserUids(array $additionalUsers = ['www-data'], ?callable $listUsers = null, ?callable $uidResolver = null): array
{
    $listUsers = $listUsers ?? ['userFilesystem', 'listManagedUsersWithAdditionalUsers'];
    $uidResolver = $uidResolver ?? 'pmssResourceLogLookupManagedUid';
    $result = [];

    foreach ($listUsers($additionalUsers) as $user) {
        $user = (string) $user;
        if (!is_int($uid = $uidResolver($user))) continue;
        $result[$user] = $uid;
    }

    return $result;
}

function pmssAppendRootTimestampedLogEntry(string $path, string $message, int $mode = 0644): bool
{
    return pmssAppendUserFile($path, date('Y-m-d H:i:s').$message, 'root', $mode);
}

/**
 * Acquire a counter state file lock while creating missing files owner-only.
 */
function pmssCounterStateLockAcquire(string $statePath)
{
    if (!pmssPathTargetIsSafe($statePath, false)) {
        return false;
    }

    $previousUmask = umask(0177);
    try {
        return pmssLockFileAcquire($statePath, false, 'c+');
    } finally {
        umask($previousUmask);
    }
}

/** Persist counter state under lock and return deltas for the selected fields.
 *
 * @param array<string, int> $deltaCeilings
 * @return array{delta: array<string, int>, previous_state: array<string, mixed>, state: array<string, int>}
 */
function pmssCounterStateUpdate(string $statePath, array $state, array $deltaFields, array $deltaCeilings = []): array
{
    $handle = pmssCounterStateLockAcquire($statePath);
    $previousState = $handle !== false ? (pmssJsonDecodeAssoc((string) @stream_get_contents($handle)) ?? []) : [];
    $delta = [];
    foreach ($deltaFields as $field) {
        $currentValue = array_key_exists($field, $state) ? (int) $state[$field] : 0;
        $previous = $previousState[$field] ?? null;
        $previousValue = is_int($previous) && $previous >= 0 ? $previous : null;
        if (is_string($previous) && ctype_digit($previous)) {
            $previousValue = (int) $previous;
        }
        $candidateDelta = $previousValue !== null && $currentValue >= $previousValue
            ? $currentValue - $previousValue
            : $currentValue;
        $deltaLimit = $deltaCeilings[$field] ?? null;
        $delta[$field] = is_int($deltaLimit) && $deltaLimit >= 0 && $candidateDelta > $deltaLimit
            ? 0
            : $candidateDelta;
    }

    if ($handle !== false && is_string($payload = pmssJsonEncodeSafe($state))) {
        @ftruncate($handle, 0);
        @rewind($handle);
        @fwrite($handle, $payload);
        @fflush($handle);
        chmod($statePath, 0600);
    }
    if ($handle !== false) { pmssLockHandleRelease($handle); }
    return ['delta' => $delta, 'previous_state' => $previousState, 'state' => $state];
}

/**
 * Check whether five minutes of usage exceed 90% of the configured link budget.
 */
function pmssResourceLogExceedsFiveMinuteLinkBudget(int $bytes, ?float $linkSpeed): bool
{
    return $linkSpeed !== null
        && $linkSpeed > 0
        && $bytes > ($linkSpeed * 1000 * 1000 * 60 * 5) * 0.9;
}

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

    if (($bytes = pmssResourceLogReadBlkioReadWrite($root.'/blkio'.$slice.'blkio.throttle.io_service_bytes')) !== null) {
        $values['io_read'] = $bytes['read'];
        $values['io_write'] = $bytes['write'];
    }
    if (($ops = pmssResourceLogReadBlkioReadWrite($root.'/blkio'.$slice.'blkio.throttle.io_serviced')) !== null) {
        $values['io_read_ops'] = $ops['read'];
        $values['io_write_ops'] = $ops['write'];
    }

    // v1 memory.stat uses rss/cache where v2 uses anon/file; map to the shared output keys.
    foreach (['rss' => 'memory_anon', 'cache' => 'memory_file'] as $field => $key) {
        if (($value = pmssResourceLogReadMemoryStatField($root.'/memory'.$slice.'memory.stat', $field)) !== null) $values[$key] = $value;
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

/** Sum the Read/Write rows of a v1 blkio per-device file (blkio.throttle.io_service_bytes / io_serviced). */
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
        $totals[$m[1] === 'Read' ? 'read' : 'write'] += $value;
        $matched = true;
    }

    return $matched ? $totals : null;
}

/** Read one named field from a v1 memory.stat file. */
function pmssResourceLogReadMemoryStatField(string $path, string $field): ?int
{
    $raw = pmssReadRegularFileContents($path);
    if ($raw === null || trim($raw) === '') return null;

    foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
        [$name, $value] = array_pad(preg_split('/\s+/', trim((string) $line), 2), 2, null);
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
            [$field, $value] = array_pad(preg_split('/\s+/', trim($line), 2), 2, null);
            if (!isset($memoryFields[$field]) || !ctype_digit((string) $value)) {
                continue;
            }
            $breakdown[$memoryFields[$field]] = (int) $value;
        }

        if (count($breakdown) === count($memoryFields)) { return $breakdown; }
    }

    return null;
}

/** Update the resource state file and return the latest interval deltas.
 *
 * @return array{delta: array, state: array}
 */
function pmssResourceLogUpdateState(string $statePath, array $counters): array
{
    $state = [
        'memory' => array_key_exists('memory', $counters) ? (int) $counters['memory'] : 0,
        'tasks' => array_key_exists('tasks', $counters) ? (int) $counters['tasks'] : 0,
        'ts' => time(),
    ];
    foreach (['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu_nsec'] as $field) {
        $state[$field] = array_key_exists($field, $counters) ? (int) $counters[$field] : 0;
    }
    foreach (pmssResourceMemoryBreakdownFieldMap() as $field) { array_key_exists($field, $counters) && $state[$field] = (int) $counters[$field]; }

    $result = pmssCounterStateUpdate(
        $statePath,
        $state,
        ['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu_nsec'],
        [
            'io_read' => PMSS_RESOURCE_LOG_MAX_INTERVAL_IO_BYTES,
            'io_write' => PMSS_RESOURCE_LOG_MAX_INTERVAL_IO_BYTES,
            'io_read_ops' => PMSS_RESOURCE_LOG_MAX_INTERVAL_IO_OPS,
            'io_write_ops' => PMSS_RESOURCE_LOG_MAX_INTERVAL_IO_OPS,
        ]
    );
    return ['delta' => $result['delta'], 'state' => $state];
}
