<?php
/**
 * Disk-space decisions for the phase-2 updater preflight.
 * The standalone bootstrap keeps matching copies of the pure functions.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/runtime/commands.php';

if (!function_exists('pmssRootUsableDiskBytes')) {
    /** Parse statvfs free blocks and fragment size; reject malformed output. */
    function pmssRootUsableDiskBytes(?string $statOutput): ?float
    {
        if ($statOutput === null || !preg_match('/^([0-9]+)\s+([0-9]+)$/D', trim($statOutput), $matches)) {
            return null;
        }
        $blocks = (float) $matches[1];
        $fragmentSize = (float) $matches[2];
        $bytes = $blocks * $fragmentSize;
        return $fragmentSize > 0 && is_finite($bytes) ? $bytes : null;
    }
}

if (!function_exists('pmssDiskPreflightAvailableBytes')) {
    /** Root may use the /home reserve; other checks use the user allowance. */
    function pmssDiskPreflightAvailableBytes(string $path, int $effectiveUid, float $userAvailable, ?float $rootUsable): float
    {
        return $path === '/home' && $effectiveUid === 0 && $rootUsable !== null ? $rootUsable : $userAvailable;
    }
}

/** Measure statvfs free blocks through the shared guarded command runner. */
function pmssRootUsableDiskMeasure(string $path): ?float
{
    $result = pmssCommandCapture(pmssBuildCommand('stat', ['-f', '-c', '%f %S', '--', $path]));
    return $result['rc'] === 0 ? pmssRootUsableDiskBytes($result['stdout']) : null;
}
