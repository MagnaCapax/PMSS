<?php
/**
 * Helpers for per-user ingress traffic accounting via systemd IPAccounting.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../resources/userHelpers.php';

/**
 * Ensure a directory exists and is safe for use by ingress logging.
 */
function pmssTrafficIngressEnsureDir(string $path, int $mode): bool
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
 * Read systemd IPAccounting counters for the user's slice.
 */
function pmssTrafficIngressReadCounters(int $uid): ?array
{
    if (trim((string) @shell_exec('command -v systemctl 2>/dev/null')) === '') {
        return null;
    }
    $unit = sprintf('user-%d.slice', $uid);
    $cmd = 'systemctl show '.escapeshellarg($unit).' -p IPIngressBytes -p IPEgressBytes';
    $out = trim((string) @shell_exec($cmd));
    if ($out === '') {
        return null;
    }
    $ingress = null;
    $egress = null;
    foreach (preg_split('/\r?\n/', trim($out)) as $line) {
        if (strpos($line, 'IPIngressBytes=') === 0) {
            $value = substr($line, strlen('IPIngressBytes='));
            if (ctype_digit($value)) {
                $ingress = (int) $value;
            }
        } elseif (strpos($line, 'IPEgressBytes=') === 0) {
            $value = substr($line, strlen('IPEgressBytes='));
            if (ctype_digit($value)) {
                $egress = (int) $value;
            }
        }
    }
    return ($ingress === null || $egress === null)
        ? null
        : ['ingress' => $ingress, 'egress' => $egress];
}

/**
 * Load the last-seen counters from a state file.
 */
function pmssTrafficIngressReadState(string $path): array
{
    $raw = is_file($path) ? @file_get_contents($path) : false;
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Persist the latest counters to a state file.
 */
function pmssTrafficIngressWriteState(string $path, array $state): void
{
    $payload = json_encode($state);
    if (!is_string($payload)) {
        return;
    }
    @file_put_contents($path, $payload);
    @chmod($path, 0600);
}
