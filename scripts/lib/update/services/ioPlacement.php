<?php
/**
 * Opt-in cgroup-v1 aggregate I/O placement; never restart account services.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../../cgroup/policy.php';
require_once __DIR__.'/../managedPath.php';

/** Converge the manager default before the existing daemon-reexec step. */
function pmssCgroupIoPlacementConfigure(array $policy, string $mode, string $directory = '/etc/systemd/system.conf.d', ?callable $logger = null): bool
{
    $log = $logger ?: 'logMessage';
    $path = rtrim($directory, '/').'/90-pmss-io-placement.conf';
    $enabled = $mode === 'v1' && ($policy['cgroup']['ioSlicePlacement'] ?? false) === true;
    if (!$enabled && !pmssPathExistsOrLink($path)) return true;
    if (pmssEnvFlagEnabled('PMSS_DRY_RUN')) {
        $log('[DRY-RUN] '.($enabled ? 'Install' : 'Remove').' aggregate I/O placement default');
        return true;
    }
    if (!$enabled) return pmssRemoveManagedPathFile($path, 'aggregate I/O placement default', $log);

    $template = pmssReadRegularFileContents(rtrim(pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config'), '/').'/template.systemd.io-placement.conf');
    if ($template === null || $template === '') {
        $log('[WARN] Missing aggregate I/O placement template; retaining manager defaults');
        return false;
    }
    $succeeded = false;
    pmssRefreshManagedPathFile($path, $template, 'aggregate I/O placement default', $log,
        pmssManagedPathInstallOptions($path, 'aggregate I/O placement default'), $succeeded);
    if ($succeeded) {
        $log('[WARN] Aggregate I/O placement enabled: existing user managers may retain child blkio groups until a planned restart or reboot; no account services restarted');
    }
    return $succeeded;
}
