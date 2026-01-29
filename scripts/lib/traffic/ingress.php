<?php
/**
 * Helpers for per-user ingress traffic accounting via systemd IPAccounting.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Ensure a directory exists and is safe for use by ingress logging.
 */
function pmssTrafficIngressEnsureDir(string $path, int $mode): bool
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
 * Resolve a username to its UID with a POSIX-first fallback.
 */
function pmssTrafficIngressLookupUid(string $user): ?int
{
    if (function_exists('posix_getpwnam')) {
        $info = @posix_getpwnam($user);
        if (is_array($info) && isset($info['uid'])) {
            return (int) $info['uid'];
        }
    }
    $out = trim((string) @shell_exec('id -u '.escapeshellarg($user).' 2>/dev/null'));
    if ($out === '' || !ctype_digit($out)) {
        return null;
    }
    return (int) $out;
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
    $out = @shell_exec($cmd);
    if (!is_string($out) || trim($out) === '') {
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
    if ($ingress === null || $egress === null) {
        return null;
    }
    return ['ingress' => $ingress, 'egress' => $egress];
}

/**
 * Load the last-seen counters from a state file.
 */
function pmssTrafficIngressReadState(string $path): array
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
function pmssTrafficIngressWriteState(string $path, array $state): void
{
    $payload = json_encode($state);
    if (!is_string($payload)) {
        return;
    }
    @file_put_contents($path, $payload);
    @chmod($path, 0600);
}
