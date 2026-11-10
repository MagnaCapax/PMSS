<?php
/**
 * Required host-service recovery for the systemd cron guard (Refs #1256).
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../systemd.php';
require_once __DIR__.'/../../../nginxService.php';

/**
 * Start only explicitly enabled, stopped required units outside PMSS updates.
 *
 * Holding the updater's lock through all actions closes the probe/start race.
 * The early-boot hardening unit also invokes this guard: never queue starts
 * before basic.target or during shutdown, where dependency ordering can deadlock.
 *
 * @param string $lockFile Canonical updater lock; injectable for hermetic tests.
 */
function pmssRecoverRequiredSystemdServices(string $lockFile = '/var/lib/pmss/update.lock'): void
{
    if (!pmssSystemdRuntimeAvailable()) {
        return;
    }
    $lock = pmssLockFileAcquire($lockFile, true, 'c', true, true, $busy);
    if ($lock === false) {
        logMessage($busy ? '[SKIP] Required service recovery: update lock busy'
            : '[WARN] Required service recovery: update lock unavailable');
        return;
    }

    $previousFds = getenv(PMSS_UPDATE_LOCK_FDS_ENV);
    try {
        // Started daemons must not inherit the updater lock from shell children.
        pmssLockHandleExportChildCloseFds($lock);
        foreach (['nginx', 'ssh', 'cron', 'proftpd'] as $unit) {
            $manager = trim((string) @shell_exec('systemctl is-system-running 2>/dev/null'));
            if (!in_array($manager, ['running', 'degraded'], true)) {
                return;
            }
            if (!in_array(pmssSystemdUnitState('is-enabled', $unit), ['enabled', 'enabled-runtime'], true)
                || !in_array(pmssSystemdUnitState('is-active', $unit), ['inactive', 'failed'], true)) {
                continue;
            }

            // Keep nginx's existing config test and managed-master verification.
            $command = $unit === 'nginx' ? 'nginx -t && '.pmssNginxServiceCommand('start', true)
                : 'systemctl start '.escapeshellarg($unit).' && systemctl is-active --quiet '.escapeshellarg($unit);
            runStep('Recovering required system service '.$unit, $command);
        }
    } finally {
        putenv($previousFds === false ? PMSS_UPDATE_LOCK_FDS_ENV : PMSS_UPDATE_LOCK_FDS_ENV.'='.$previousFds);
        pmssLockHandleRelease($lock);
    }
}
