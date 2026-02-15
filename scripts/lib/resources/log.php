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
    if (trim((string) @shell_exec('command -v systemctl 2>/dev/null')) === '') {
        return null;
    }
    $unit = sprintf('user-%d.slice', $uid);
    $cmd = 'systemctl show '.escapeshellarg($unit)
        .' -p IOReadBytes -p IOWriteBytes -p CPUUsageNSec -p MemoryCurrent -p TasksCurrent';
    $out = @shell_exec($cmd);
    if (!is_string($out) || trim($out) === '') {
        return null;
    }

    $keys = [
        'IOReadBytes' => 'io_read',
        'IOWriteBytes' => 'io_write',
        'CPUUsageNSec' => 'cpu_nsec',
        'MemoryCurrent' => 'memory',
        'TasksCurrent' => 'tasks',
    ];
    $values = array_fill_keys(array_values($keys), null);

    foreach (preg_split('/\r?\n/', trim($out)) as $line) {
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2 || !isset($keys[$parts[0]])) {
            continue;
        }
        $value = $parts[1];
        if (ctype_digit($value)) {
            $values[$keys[$parts[0]]] = (int) $value;
        }
    }

    if (in_array(null, $values, true)) {
        return null;
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
    $state = [];
    $handle = @fopen($statePath, 'c+');
    $locked = false;
    if ($handle !== false) {
        $locked = @flock($handle, LOCK_EX);
        if ($locked) {
            $decoded = json_decode((string) @stream_get_contents($handle), true);
            if (is_array($decoded)) {
                $state = $decoded;
            }
        }
    }

    $current = [];
    $delta = [];
    foreach (['io_read', 'io_write', 'cpu_nsec'] as $field) {
        $currentValue = (int) $counters[$field];
        $previousValue = isset($state[$field]) ? (int) $state[$field] : null;
        $current[$field] = $currentValue;
        $delta[$field] = ($previousValue !== null && $currentValue >= $previousValue) ? $currentValue - $previousValue : $currentValue;
    }

    $state = $current + [
        'memory' => (int) $counters['memory'],
        'tasks' => (int) $counters['tasks'],
        'ts' => time(),
    ];

    $payload = json_encode($state);
    if ($locked && is_string($payload)) {
        @ftruncate($handle, 0);
        @rewind($handle);
        @fwrite($handle, $payload);
        @fflush($handle);
        @chmod($statePath, 0600);
    }

    if ($locked) {
        @flock($handle, LOCK_UN);
    }
    if ($handle !== false) {
        @fclose($handle);
    }

    return ['delta' => $delta, 'state' => $state];
}
