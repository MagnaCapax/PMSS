<?php
/**
 * NVMe storage-health snapshot backend.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/common.php';
require_once __DIR__.'/deviceParse.php';

/**
 * @param array<string, mixed> $disk
 * @param array<string, mixed> $last
 * @return array<string, mixed>|null
 */
function pmssStorageHealthSnapshotNvme(array $disk, array $last, string $timestamp): ?array
{
    // Malformed inventory values must not warn or invoke object conversion hooks.
    if (!is_scalar($disk['path'] ?? null)) return null;
    // Optional metadata is cast for the probe lock and final entry; null keeps its defaults.
    foreach (['kname', 'model', 'serial', 'rota', 'size'] as $field) {
        if (isset($disk[$field]) && !is_scalar($disk[$field])) return null;
    }
    $dev = (string) $disk['path'];
    // Corrupt paths use the unavailable result before shell quoting can throw.
    if (pmssFilesystemPathHasNulByte($dev)) {
        return null;
    }
    if (strpos($dev, 'nvme') === false || pmssCommandPath('nvme') === '') {
        return null;
    }

    $probe = pmssStorageHealthProbeCommand('nvme', (string) ($disk['kname'] ?? ''), 'nvme smart-log '.escapeshellarg($dev).' 2>/dev/null');
    $result = pmssCommandCapture($probe['command'], 20);
    // Failed or timed-out probes can leave partial metrics in captured stdout.
    if ($result['rc'] !== 0 || $result['stdout'] === '') {
        return null;
    }

    $previous = $last['nvme::'.$dev]['metrics'] ?? null;
    return pmssStorageHealthParseNvmeOutput($result['stdout'], $disk, is_array($previous) ? $previous : null, $timestamp);
}
