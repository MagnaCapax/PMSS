<?php
/**
 * Systemd service hardening for seedbox hosts.
 *
 * PMSS runs most user-facing daemons per-user (under /home/<user>) and relies
 * on nginx as the shared front-end. System-wide units for apps like Deluge
 * should remain stopped/disabled to reduce attack surface.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../runtime/processes.php';

/**
 * Stop + disable (and optionally mask) a unit, fail-soft.
 *
 * This helper is intentionally tolerant: on some hosts the unit may not be
 * installed, and `systemctl` may be unavailable in containers.
 */
function pmssStopDisableMaskSystemdUnit(string $unit, string $label, bool $mask): void
{
    $dryRun = getenv('PMSS_DRY_RUN') === '1';
    $actions = ['stop' => 'Stopping', 'disable' => 'Disabling'] + ($mask ? ['mask' => 'Masking'] : []);
    $skipReason = !$dryRun && !is_dir('/run/systemd/system')
        ? 'systemd unavailable'
        : (!$dryRun && !pmssSystemdUnitExists($unit) ? 'unit '.$unit.' missing' : '');

    if ($skipReason !== '') {
        foreach ($actions as $prefix) {
            pmssLogStatus('SKIP', $prefix.' '.$label.' system service ('.$skipReason.')');
        }
        return;
    }

    foreach ($actions as $verb => $prefix) {
        runStep($prefix.' '.$label.' system service', 'systemctl '.$verb.' '.escapeshellarg($unit).' || true');
    }
}

/**
 * Install and enable the boot-time systemd hardening guard unit.
 *
 * This is defense-in-depth: the unit runs early in boot (before basic.target)
 * so distro-provided services cannot start before PMSS reasserts stop/disable/mask
 * policy via scripts/cron/systemdServicesGuard.php.
 */
function pmssEnsureSystemdServicesGuardBootUnit(): void
{
    if (getenv('PMSS_DRY_RUN') !== '1' && !is_dir('/run/systemd/system')) {
        pmssLogStatus('SKIP', 'Installing PMSS boot-time systemd services guard unit (systemd unavailable)');
        return;
    }

    $cfgDir = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config');

    $template = $cfgDir.'/template.systemd.pmss-systemd-services-guard.service';
    if (!is_file($template)) {
        pmssLogStatus('SKIP', 'Installing PMSS boot-time systemd services guard unit (template missing: '.$template.')');
        return;
    }

    runStep('Installing PMSS boot-time systemd services guard unit', sprintf('install -m 0644 %s %s', escapeshellarg($template), escapeshellarg('/etc/systemd/system/pmss-systemd-services-guard.service')));
    runStep('Reloading systemd unit files (PMSS services guard)', 'systemctl daemon-reload || true');
    runStep('Enabling PMSS boot-time services guard unit', 'systemctl enable pmss-systemd-services-guard.service || true');
}

/**
 * Specs for system-wide services that must stay disabled on seedbox hosts.
 *
 * Keep this list conservative and system-wide only. Per-user instances are
 * managed by PMSS cron/util scripts and must not be impacted.
 *
 * @return array<int, array{unit:string,label:string,mask:bool}>
 */
function pmssSeedboxSystemServiceSpecs(): array
{
    return [
        ['unit' => 'lighttpd', 'label' => 'lighttpd', 'mask' => true],
        ['unit' => 'deluged', 'label' => 'Deluge daemon', 'mask' => true],
        ['unit' => 'deluge-web', 'label' => 'Deluge Web UI', 'mask' => true],
        ['unit' => 'transmission-daemon', 'label' => 'Transmission daemon', 'mask' => true],
        ['unit' => 'redis-server', 'label' => 'Redis server', 'mask' => true],
        ['unit' => 'memcached', 'label' => 'Memcached', 'mask' => true],
        ['unit' => 'rpcbind', 'label' => 'rpcbind', 'mask' => true],
        ['unit' => 'rpcbind.socket', 'label' => 'rpcbind socket', 'mask' => true],
        ['unit' => 'nfs-kernel-server', 'label' => 'NFS kernel server', 'mask' => true],
        ['unit' => 'nfs-server', 'label' => 'NFS server', 'mask' => true],
        ['unit' => 'nfs-idmapd', 'label' => 'NFS idmapd', 'mask' => true],
        ['unit' => 'rpc-statd', 'label' => 'rpc-statd', 'mask' => true],
        ['unit' => 'smbd', 'label' => 'Samba smbd', 'mask' => true],
        ['unit' => 'nmbd', 'label' => 'Samba nmbd', 'mask' => true],
        ['unit' => 'samba', 'label' => 'Samba (meta)', 'mask' => true],
        ['unit' => 'avahi-daemon', 'label' => 'Avahi mDNS', 'mask' => true],
        ['unit' => 'avahi-daemon.socket', 'label' => 'Avahi mDNS socket', 'mask' => true],
        ['unit' => 'cups', 'label' => 'CUPS printing', 'mask' => true],
        ['unit' => 'cups.socket', 'label' => 'CUPS socket', 'mask' => true],
        ['unit' => 'cups.path', 'label' => 'CUPS path', 'mask' => true],
        ['unit' => 'cups-browsed', 'label' => 'CUPS browsed', 'mask' => true],
        // Docker must run rootless per-user; the system daemon must stay off.
        ['unit' => 'docker.service', 'label' => 'Docker (system)', 'mask' => true],
        ['unit' => 'docker.socket', 'label' => 'Docker socket (system)', 'mask' => true],
        ['unit' => 'containerd', 'label' => 'containerd (system)', 'mask' => true],
        // Exim4 is pulled in indirectly; PMSS does not use a system MTA.
        ['unit' => 'exim4', 'label' => 'Exim4 MTA', 'mask' => true],
        // qBittorrent-nox typically runs per-user; this is a no-op on hosts without a unit.
        ['unit' => 'qbittorrent-nox', 'label' => 'qBittorrent (system)', 'mask' => true],
    ];
}

/**
 * Stop/disable known risky system-wide services.
 *
 * Note: per-user instances are started via PMSS cron/util scripts and are
 * not impacted by masking system-level units.
 */
function pmssStopDisableMaskSeedboxSystemServices(): void
{
    foreach (pmssSeedboxSystemServiceSpecs() as $spec) {
        pmssStopDisableMaskSystemdUnit($spec['unit'], $spec['label'], $spec['mask']);
    }

    runStep('Purging exim4 packages', aptCmd('purge -y exim4 exim4-base exim4-config exim4-daemon-light'));
    runStep('Autoremoving orphaned packages after exim4 purge', aptCmd('autoremove -y'));
    // Exim can be reinstalled indirectly by distro package relationships,
    // so this flow keeps the host converged back to a no-exim state.
    // Deletion is intentionally limited to known exim4 spool directories
    // and uses one command per directory for predictable logging/retries.
    foreach (['/var/spool/exim4/input', '/var/spool/exim4/msglog', '/var/spool/exim4/db'] as $dir) {
        runStep('Purging stale exim4 spool files in '.$dir, 'find '.escapeshellarg($dir).' -xdev -type f -delete 2>/dev/null || true');
    }
}

/**
 * Purge the unbound DNS resolver if its service is in failed state.
 *
 * Debian 11/12 servers may have unbound installed but failing because
 * external nameservers (1.1.1.1, 1.0.0.1) are configured instead. When
 * the service is in failed state, purge the package to eliminate noise
 * in systemd status and logs.
 *
 * Only removes when the service is explicitly "failed" - not when it is
 * active, inactive, or not installed.
 */
function pmssPurgeFailedUnbound(): void
{
    // Skip if systemd is not available (containers, very old systems)
    if (getenv('PMSS_DRY_RUN') !== '1' && !is_dir('/run/systemd/system')) {
        pmssLogStatus('SKIP', 'Checking unbound service status (systemd unavailable)');
        return;
    }

    if (trim((string) @shell_exec('systemctl is-active unbound 2>/dev/null')) !== 'failed') {
        return;
    }

    runStep('Purging failed unbound service', aptCmd('purge -y unbound'));
}
