<?php
/**
 * Customer-readable host I/O pressure snapshot support.
 *
 * @license GPL-3.0-only
 * @author  PMSS Team
 */

require_once dirname(__DIR__).'/lighttpd/userFileWrite.php';

/** Parse one finite, non-negative metric with an optional unit suffix. */
function pmssSystemStatsHostPressureMetricParse($value, string $suffix = ''): ?float
{
    if (!is_scalar($value)) return null;

    $value = trim((string) $value);
    if ($suffix !== '') {
        if (strlen($value) <= strlen($suffix) || substr($value, -strlen($suffix)) !== $suffix) return null;
        $value = substr($value, 0, -strlen($suffix));
    }
    if (!is_numeric($value)) return null;

    $number = (float) $value;
    return is_finite($number) && $number >= 0 ? $number : null;
}

/** Build the narrow metric payload exposed to customer-side PHP. */
function pmssSystemStatsHostPressurePayloadBuild(array $stats, ?int $timestamp = null): array
{
    $psiFields = explode('/', (string) ($stats['psiIo'] ?? ''));
    return [
        'timestamp' => $timestamp ?? time(),
        // systemStats PSI order keeps full_avg300 at index 5.
        'psi_io_full_avg300' => isset($psiFields[5])
            ? pmssSystemStatsHostPressureMetricParse($psiFields[5])
            : null,
        'ioping_home_ms' => pmssSystemStatsHostPressureMetricParse($stats['iopingHome'] ?? null, 'ms'),
    ];
}

/** Atomically publish the latest host-pressure snapshot as root:root 0644. */
function pmssSystemStatsHostPressureSnapshotWrite(string $path, array $stats, ?int $timestamp = null): bool
{
    $payload = pmssSystemStatsHostPressurePayloadBuild($stats, $timestamp);
    $json = pmssJsonEncodeSafe($payload, JSON_UNESCAPED_SLASHES);
    return is_string($json) && pmssAtomicWriteFile($path, $json.PHP_EOL, 0644);
}
