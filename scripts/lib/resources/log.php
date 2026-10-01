<?php
/**
 * Helpers for per-user resource metering via systemd slice accounting.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../userLifecycle.php';
pmssRequireRelativeFiles(dirname(__DIR__), [
    'lighttpd/userFileWrite.php', 'user/userFilesystem.php',
]);
require_once __DIR__.'/counterState.php';
require_once __DIR__.'/counters.php';

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
 * Check whether five minutes of usage exceed 90% of the configured link budget.
 */
function pmssResourceLogExceedsFiveMinuteLinkBudget(int $bytes, ?float $linkSpeed): bool
{
    return $linkSpeed !== null
        && $linkSpeed > 0
        && $bytes > ($linkSpeed * 1000 * 1000 * 60 * 5) * 0.9;
}

/** Update the resource state file and return the latest interval deltas.
 *
 * @return array{delta: array, state: array}
 */
function pmssResourceLogUpdateState(string $statePath, array $counters): array
{
    $state = [
        'memory' => (int) ($counters['memory'] ?? 0),
        'tasks' => (int) ($counters['tasks'] ?? 0),
        'ts' => time(),
    ];
    $deltaFields = ['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu_nsec'];
    foreach ($deltaFields as $field) {
        $state[$field] = (int) ($counters[$field] ?? 0);
    }
    foreach (pmssResourceMemoryBreakdownFieldMap() as $field) { array_key_exists($field, $counters) && $state[$field] = (int) $counters[$field]; }
    // Persist which blkio accounting source the io_* counters came from (bfq/throttle), so the
    // next interval can detect a source switch and reseed the baseline (phantom-delta guard).
    if (array_key_exists('io_source', $counters)) { $state['io_source'] = (string) $counters['io_source']; }

    $result = pmssCounterStateUpdate(
        $statePath,
        $state,
        $deltaFields,
        [
            'io_read' => PMSS_RESOURCE_LOG_MAX_INTERVAL_IO_BYTES,
            'io_write' => PMSS_RESOURCE_LOG_MAX_INTERVAL_IO_BYTES,
            'io_read_ops' => PMSS_RESOURCE_LOG_MAX_INTERVAL_IO_OPS,
            'io_write_ops' => PMSS_RESOURCE_LOG_MAX_INTERVAL_IO_OPS,
        ]
    );

    // Phantom-delta guard (#707 §0): the io_* counters feed live monthly-IOPS enforcement
    // (pmssReadUserMonthlyIopsUsage -> io_read_ops/io_write_ops raw.month). When the accounting
    // SOURCE changes — throttle->bfq on this fix, or a cgroup-mode flip changing systemd<->v1 —
    // the stored baseline belonged to a different counter, so the raw delta would be the full
    // cumulative and inflate the month total, risking a spurious /home IOPS throttle. On a
    // source change, emit zero io_* deltas for this one sample; the new baseline is now stored,
    // so subsequent intervals delta normally.
    $previousSource = $result['previous_state']['io_source'] ?? null;
    if ($previousSource !== ($state['io_source'] ?? null)) {
        foreach (['io_read', 'io_write', 'io_read_ops', 'io_write_ops'] as $field) { $result['delta'][$field] = 0; }
    }

    return ['delta' => $result['delta'], 'state' => $state];
}
