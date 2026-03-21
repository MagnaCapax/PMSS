<?php
/**
 * Helpers for per-user resource metering via systemd slice accounting.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Resolve a username to its UID with a POSIX-first fallback.
 */
function pmssResourceLogLookupUid(string $user): ?int
{
    if (function_exists('posix_getpwnam') && is_array($info = @posix_getpwnam($user)) && isset($info['uid'])) {
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
    if (!is_dir($path) && !@mkdir($path, $mode, true)) {
        return false;
    }
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
        if (count($parts = explode('=', $line, 2)) !== 2 || !ctype_digit($parts[1]) || !isset($fieldMap[$parts[0]])) {
            continue;
        }
        $values[$fieldMap[$parts[0]]] = (int) $parts[1];
    }

    if (!isset($values['io_read'], $values['io_write'], $values['cpu_nsec'], $values['memory'], $values['tasks'])) {
        return null;
    }

    if (is_array($memoryBreakdown = pmssResourceLogReadMemoryBreakdown($uid))) {
        $values += $memoryBreakdown;
    }

    return $values;
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
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            continue;
        }

        $anon = null;
        $file = null;
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            if (count($parts = preg_split('/\s+/', trim($line), 2)) !== 2 || !ctype_digit($parts[1])) {
                continue;
            }
            if ($parts[0] === 'anon') {
                $anon = (int) $parts[1];
            } elseif ($parts[0] === 'file') {
                $file = (int) $parts[1];
            }
        }

        if ($anon !== null && $file !== null) {
            return ['memory_anon' => $anon, 'memory_file' => $file];
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
    $previousState = [];
    if ($locked && is_array($decoded = json_decode((string) @stream_get_contents($handle), true))) {
        $previousState = $decoded;
    }

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
