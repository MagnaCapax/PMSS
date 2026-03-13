<?php
/**
 * Helpers for per-user resource metering via systemd slice accounting.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/userHelpers.php';

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
    $out = @shell_exec($cmd);
    if (!is_string($out) || trim($out) === '') {
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
    $values = [
        'io_read' => null,
        'io_write' => null,
        'cpu_nsec' => null,
        'memory' => null,
        'tasks' => null,
        'io_read_ops' => 0,
        'io_write_ops' => 0,
    ];

    foreach (preg_split('/\r?\n/', trim($out)) as $line) {
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2 || ($field = $fieldMap[$parts[0]] ?? null) === null || !ctype_digit($parts[1])) {
            continue;
        }
        $values[$field] = (int) $parts[1];
    }

    foreach (['io_read', 'io_write', 'cpu_nsec', 'memory', 'tasks'] as $field) {
        if (!isset($values[$field])) {
            return null;
        }
    }

    return $values;
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
    if ($locked) {
        $decoded = json_decode((string) @stream_get_contents($handle), true);
        if (is_array($decoded)) {
            $previousState = $decoded;
        }
    }

    $state = $delta = [];
    foreach (['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu_nsec'] as $field) {
        $currentValue = (int) $counters[$field];
        $previousValue = isset($previousState[$field]) ? (int) $previousState[$field] : null;
        $delta[$field] = ($previousValue !== null && $currentValue >= $previousValue) ? $currentValue - $previousValue : $currentValue;
        $state[$field] = $currentValue;
    }

    $state['memory'] = (int) $counters['memory'];
    $state['tasks'] = (int) $counters['tasks'];
    $state['ts'] = time();

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
