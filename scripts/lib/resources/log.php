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

/**
 * Resolve a validated managed username to its UID.
 */
function pmssResourceLogLookupManagedUid(string $user): ?int
{
    if (!pmssResourceUserIsValid($user)) {
        return null;
    }
    if (($info = pmssUserAccountLookup($user)) !== null) {
        return (int) $info['uid'];
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

/** Persist counter state under lock and return deltas for the selected fields.
 *
 * @return array{delta: array<string, int>, previous_state: array<string, mixed>, state: array<string, int>}
 */
function pmssCounterStateUpdate(string $statePath, array $state, array $deltaFields): array
{
    $handle = pmssPathTargetIsSafe($statePath, false) ? pmssLockFileAcquire($statePath, false, 'c+') : false;
    $previousState = $handle !== false && is_array($decoded = json_decode((string) @stream_get_contents($handle), true))
        ? $decoded
        : [];
    $delta = [];
    foreach ($deltaFields as $field) {
        $currentValue = array_key_exists($field, $state) ? (int) $state[$field] : 0;
        $previous = $previousState[$field] ?? null;
        $previousValue = is_int($previous) && $previous >= 0 ? $previous : null;
        if (is_string($previous) && ctype_digit($previous)) {
            $previousValue = (int) $previous;
        }
        $delta[$field] = $previousValue !== null && $currentValue >= $previousValue
            ? $currentValue - $previousValue
            : $currentValue;
    }

    if ($handle !== false && is_string($payload = pmssJsonEncodeSafe($state))) {
        @ftruncate($handle, 0);
        @rewind($handle);
        @fwrite($handle, $payload);
        @fflush($handle);
        @chmod($statePath, 0600);
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

/**
 * Read systemd slice counters for the given user.
 */
function pmssResourceLogReadCounters(int $uid): ?array
{
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

    $memoryBreakdown = pmssResourceLogReadMemoryBreakdown($uid);
    return is_array($memoryBreakdown) ? $values + $memoryBreakdown : $values;
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

    $result = pmssCounterStateUpdate($statePath, $state, ['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu_nsec']);
    return ['delta' => $result['delta'], 'state' => $state];
}
