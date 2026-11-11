<?php
/** Disk-backed temporary-mount policy; loaded by systemPrep.php. @license GPL-3.0-only */

/**
 * Keep /tmp disk-backed on Debian 13+ by masking the systemd tmpfs unit.
 */
function pmssConfigureTempDiskBackedMount(?callable $logger = null, ?int $distroVersion = null): void
{
    $log = $logger ?: 'logMessage';
    if ($distroVersion === null && function_exists('pmssDetectDistro')) {
        $detected = pmssDetectDistro();
        $distroVersion = (int) ($detected['version'] ?? 0);
    }

    if ((int) $distroVersion < 13) {
        $log('[SKIP] Leaving /tmp mount policy unchanged before Debian 13');
        return;
    }

    if (pmssCommandPath('systemctl') === '') {
        $log('[WARN] systemctl unavailable; unable to mask tmp.mount');
        return;
    }

    $rc = runStep('Masking systemd tmp.mount to keep /tmp disk-backed', 'systemctl mask tmp.mount');
    if ($rc !== 0) {
        $log('[WARN] Failed to mask tmp.mount; /tmp may stay tmpfs-backed until corrected');
        return;
    }

    $log('Masked tmp.mount to keep /tmp disk-backed');
}
