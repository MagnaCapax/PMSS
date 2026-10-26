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
    $dev = (string) $disk['path'];
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
