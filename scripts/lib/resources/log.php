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
    if ($path === '' || $path[0] !== '/') {
        return false;
    }
    if (is_link($path)) {
        return false;
    }
    if (file_exists($path) && !is_dir($path)) {
        return false;
    }
    if (!is_dir($path)) {
        if (!@mkdir($path, $mode, true)) {
            return false;
        }
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
 * Load the last-seen counters from a state file.
 */
function pmssResourceLogReadState(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Persist the latest counters to a state file.
 */
function pmssResourceLogWriteState(string $path, array $state): void
{
    $payload = json_encode($state);
    if (!is_string($payload)) {
        return;
    }
    @file_put_contents($path, $payload);
    @chmod($path, 0600);
}
