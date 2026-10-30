<?php
/** Installer hostname convergence. @license GPL-3.0-only */

/**
 * Apply hostname overrides provided by the installer.
 */
function pmssApplyHostnameConfig(?callable $logger = null): void
{
    $log = $logger ?: 'logMessage';
    if (!pmssEnvValueIsFalsey(getenv('PMSS_SKIP_HOSTNAME'))) {
        $log('[SKIP] Hostname configuration skipped via PMSS_SKIP_HOSTNAME');
        return;
    }

    if (($hostname = pmssEnvTrimmed('PMSS_HOSTNAME')) === '') {
        $log('[SKIP] No hostname override provided');
        return;
    }

    $hasHostnamectl = pmssCommandPath('hostnamectl') !== '';
    runStep(
        $hasHostnamectl ? 'Setting hostname via hostnamectl' : 'Setting hostname',
        $hasHostnamectl
            ? pmssBuildCommand('hostnamectl', ['set-hostname', $hostname])
            : pmssBuildCommand('hostname', [$hostname])
    );

    if (pmssHostnameRead() === $hostname) {
        $log('[SKIP] /etc/hostname already set to '.$hostname);
        return;
    }

    if (!pmssWriteManagedPathFile('/etc/hostname', $hostname.PHP_EOL, 'hostname', $log, 'root', 'root')) {
        return;
    }
    $log('Updated /etc/hostname to '.$hostname);
}
