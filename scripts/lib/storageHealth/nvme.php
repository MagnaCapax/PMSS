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

    $cmd = 'nvme smart-log '.escapeshellarg($dev).' 2>/dev/null';
    $probe = pmssStorageHealthProbeCommand('nvme', (string) ($disk['kname'] ?? ''), $cmd);
    $out = pmssCommandCapture($probe['command'], 20)['stdout'];
    if ($out === '') {
        return null;
    }

    $previous = $last['nvme::'.$dev]['metrics'] ?? null;
    return pmssStorageHealthParseNvmeOutput($out, $disk, is_array($previous) ? $previous : null, $timestamp);
}
