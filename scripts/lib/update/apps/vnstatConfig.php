<?php
/**
 * Safe refresh of the distro-owned vnStat configuration.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__, 2).'/lighttpd/userFileWrite.php';

/** Preserve the legacy vnStat settings while refusing unsafe or partial rewrites. */
function pmssVnstatConfigRefresh(string $path, callable $log, ?callable $replace = null): bool
{
    if (!pmssUserFilePathIsSafe($path) || ($config = pmssReadRegularFileContents($path)) === null) {
        $log('Warning: unable to read '.$path.'; skipping vnStat config refresh.');
        return false;
    }

    $config = str_replace('RateUnit 1', 'RateUnit 0', $config);
    // The shipped MaxBandwidth default can discard real traffic on fast links.
    // Keep the established 50 Gbit ceiling and disable unreliable speed detection.
    foreach (['MaxBandwidth' => 'MaxBandwidth 50000', 'BandwidthDetection' => 'BandwidthDetection 0'] as $setting => $line) {
        $config = preg_replace('/^'.$setting.'\s+\d+/m', $line, $config, -1, $count);
        if ($count === 0) { $config .= "\n".$line."\n"; }
    }

    // Atomic replacement keeps the old config available if staging is incomplete.
    if (!($replace ?? 'pmssReplaceUserFilePreservingMetadata')($path, $config)) {
        $log('Warning: unable to write '.$path.'; skipping vnStat restart.');
        return false;
    }
    return true;
}
