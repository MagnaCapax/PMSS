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
    $actions = ['stop' => 'Stopping', 'disable' => 'Disabling'] + ($mask ? ['mask' => 'Masking'] : []);
    if (($skipReason = pmssSystemdActionSkipReason($unit)) !== '') {
        foreach ($actions as $prefix) {
            logmsg('[SKIP] '.$prefix.' '.$label.' system service ('.$skipReason.')');
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
    if (($skipReason = pmssSystemdActionSkipReason()) !== '') {
        pmssLogStatus('SKIP', 'Installing PMSS boot-time systemd services guard unit (systemd unavailable)');
        return;
    }

    $template = pmssResolvePathFromEnv('PMSS_CONFIG_DIR', '/etc/seedbox/config').'/template.systemd.pmss-systemd-services-guard.service';
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
 * @return array<string, string>
 */
function pmssSeedboxSystemServiceSpecs(): array
{
    return [
        'lighttpd' => 'lighttpd',
        'deluged' => 'Deluge daemon',
        'deluge-web' => 'Deluge Web UI',
        'transmission-daemon' => 'Transmission daemon',
        'redis-server' => 'Redis server',
        'memcached' => 'Memcached',
        'rpcbind' => 'rpcbind',
        'rpcbind.socket' => 'rpcbind socket',
        'nfs-kernel-server' => 'NFS kernel server',
        'nfs-server' => 'NFS server',
        'nfs-idmapd' => 'NFS idmapd',
        'rpc-statd' => 'rpc-statd',
        'smbd' => 'Samba smbd',
        'nmbd' => 'Samba nmbd',
        'samba' => 'Samba (meta)',
        'avahi-daemon' => 'Avahi mDNS',
        'avahi-daemon.socket' => 'Avahi mDNS socket',
        'cups' => 'CUPS printing',
        'cups.socket' => 'CUPS socket',
        'cups.path' => 'CUPS path',
        'cups-browsed' => 'CUPS browsed',
        // Docker must run rootless per-user; the system daemon must stay off.
        'docker.service' => 'Docker (system)',
        'docker.socket' => 'Docker socket (system)',
        'containerd' => 'containerd (system)',
        // Exim4 is pulled in indirectly; PMSS does not use a system MTA.
        'exim4' => 'Exim4 MTA',
        // qBittorrent-nox typically runs per-user; this is a no-op on hosts without a unit.
        'qbittorrent-nox' => 'qBittorrent (system)',
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
    foreach (pmssSeedboxSystemServiceSpecs() as $unit => $label) {
        pmssStopDisableMaskSystemdUnit($unit, $label, true);
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
    if (($skipReason = pmssSystemdActionSkipReason()) !== '') {
        pmssLogStatus('SKIP', 'Checking unbound service status (systemd unavailable)');
        return;
    }

    if (trim((string) @shell_exec('systemctl is-active unbound 2>/dev/null')) !== 'failed') {
        return;
    }

    runStep('Purging failed unbound service', aptCmd('purge -y unbound'));
}
