<?php
/**
 * Helpers for per-user resource metering via systemd slice accounting.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../userLifecycle.php';

/**
 * Resolve a username to its UID with a POSIX-first fallback.
 */
function pmssResourceLogLookupUid(string $user): ?int
{
    if (($info = pmssUserAccountLookup($user)) !== null) {
        return (int) $info['uid'];
    }
    return ctype_digit($out = trim((string) @shell_exec('id -u '.escapeshellarg($user).' 2>/dev/null'))) ? (int) $out : null;
}

/**
 * Validate user entries from listUsers.php output.
 */
function pmssResourceLogIsValidUser(string $user): bool
{
    return (function_exists('pmssNormalizeUsername') ? pmssNormalizeUsername($user) : strtolower($user)) === $user
        && preg_match('/^[a-z0-9-]+$/', $user)
        && ($user === 'www-data'
        || !function_exists('pmssValidateUsername')
        || pmssValidateUsername($user));
}

/**
 * Ensure a directory exists and is safe for resource logging.
 */
function pmssResourceLogEnsureDir(string $path, int $mode): bool
{
    if ($path === '' || $path[0] !== '/' || is_link($path) || (file_exists($path) && !is_dir($path))) {
        return false;
    }

    @mkdir($path, $mode, true);
    @chmod($path, $mode);
    return is_dir($path);
}

/**
 * Read systemd slice counters for the given user.
 */
function pmssResourceLogReadCounters(int $uid): ?array
{
    $unit = sprintf('user-%d.slice', $uid);
    $cmd = 'systemctl show '.escapeshellarg($unit)
        .' -p IOReadBytes -p IOWriteBytes -p IOReadOperations -p IOWriteOperations -p CPUUsageNSec -p MemoryCurrent -p TasksCurrent';
    if (!is_string($out = @shell_exec($cmd)) || trim($out) === '') {
        return null;
    }

    $fieldMap = [
        'IOReadBytes' => 'io_read',
        'IOWriteBytes' => 'io_write',
        'CPUUsageNSec' => 'cpu_nsec',
        'MemoryCurrent' => 'memory',
        'TasksCurrent' => 'tasks',
        'IOReadOperations' => 'io_read_ops',
        'IOWriteOperations' => 'io_write_ops',
    ];
    $values = ['io_read_ops' => 0, 'io_write_ops' => 0];

    foreach (preg_split('/\r?\n/', trim($out)) as $line) {
        [$field, $value] = array_pad(explode('=', $line, 2), 2, null);
        if (!isset($fieldMap[$field]) || !ctype_digit((string) $value)) {
            continue;
        }
        $values[$fieldMap[$field]] = (int) $value;
    }

    if (!isset($values['io_read'], $values['io_write'], $values['cpu_nsec'], $values['memory'], $values['tasks'])) {
        return null;
    }

    $memoryBreakdown = pmssResourceLogReadMemoryBreakdown($uid);
    return is_array($memoryBreakdown) ? $values + $memoryBreakdown : $values;
}

/**
 * Read cgroup v2 memory.stat counters for the given user slice.
 */
function pmssResourceLogReadMemoryBreakdown(int $uid, ?string $cgroupRoot = null): ?array
{
    $root = rtrim($cgroupRoot ?? '/sys/fs/cgroup', '/');
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
            if (($field !== 'anon' && $field !== 'file') || !ctype_digit((string) $value)) {
                continue;
            }
            $breakdown['memory_'.$field] = (int) $value;
        }

        if (isset($breakdown['memory_anon'], $breakdown['memory_file'])) {
            return $breakdown;
        }
    }

    return null;
}

/**
 * Update the state file for a user and compute per-interval deltas.
 *
 * Uses an exclusive lock to prevent concurrent writes from corrupting state.
 *
 * @return array{delta: array, state: array}
 */
function pmssResourceLogUpdateState(string $statePath, array $counters): array
{
    $handle = @fopen($statePath, 'c+');
    $locked = $handle !== false && @flock($handle, LOCK_EX);
    $previousState = $locked && is_array($decoded = json_decode((string) @stream_get_contents($handle), true))
        ? $decoded
        : [];

    $state = $delta = [];
    foreach (['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu_nsec'] as $field) {
        $currentValue = (int) $counters[$field];
        $delta[$field] = isset($previousState[$field]) && $currentValue >= (int) $previousState[$field]
            ? $currentValue - (int) $previousState[$field]
            : $currentValue;
        $state[$field] = $currentValue;
    }
    $state += ['memory' => (int) $counters['memory'], 'tasks' => (int) $counters['tasks'], 'ts' => time()];
    if (isset($counters['memory_anon']) && isset($counters['memory_file'])) {
        $state['memory_anon'] = (int) $counters['memory_anon'];
        $state['memory_file'] = (int) $counters['memory_file'];
    }

    if ($locked && is_string($payload = json_encode($state))) {
        @ftruncate($handle, 0);
        @rewind($handle);
        @fwrite($handle, $payload);
        @fflush($handle);
        @chmod($statePath, 0600);
    }

    if ($handle !== false) {
        $locked && @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    return ['delta' => $delta, 'state' => $state];
}
