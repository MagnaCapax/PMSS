<?php
/** System service disablement, boot guard and bounded spool cleanup. @license GPL-3.0-only */
/**
 * Stop + disable (and optionally mask) a unit, fail-soft.
 *
 * This helper is intentionally tolerant: on some hosts the unit may not be
 * installed, and `systemctl` may be unavailable in containers.
 */
function pmssStopDisableMaskSystemdUnit(string $unit, string $label, bool $mask): void
{
    foreach (['stop' => 'Stopping', 'disable' => 'Disabling', 'mask' => 'Masking'] as $verb => $prefix) {
        if (!$mask && $verb === 'mask') continue;
        pmssSystemdUnitActionIfPresent($unit, $prefix.' '.$label.' system service', $verb, true);
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
    if (pmssSystemdActionSkip(pmssSystemdActionSkipReason(), 'Installing PMSS boot-time systemd services guard unit')) return;
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
        // Apache must stay purged/masked; apache2-utils remains installed for htpasswd.
        'apache2' => 'Apache httpd (legacy)',
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
 * Directories where PMSS may remove stale Exim queue files after purging exim4.
 *
 * @return string[]
 */
function pmssEximSpoolCleanupDirectories(): array
{
    return ['/var/spool/exim4/input', '/var/spool/exim4/msglog', '/var/spool/exim4/db'];
}

/**
 * Build the destructive stale-spool cleanup command only for exact allowlist hits.
 */
function pmssEximSpoolCleanupCommand(string $dir): ?string
{
    $dir = rtrim($dir, '/');
    if (!in_array($dir, pmssEximSpoolCleanupDirectories(), true) || pmssFilesystemPathHasNulByte($dir)) {
        return null;
    }

    return 'find '.escapeshellarg($dir).' -xdev -type f -delete 2>/dev/null || true';
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
    foreach (pmssEximSpoolCleanupDirectories() as $dir) {
        $command = pmssEximSpoolCleanupCommand($dir);
        if ($command === null) {
            logMessage('[WARN] Refusing unsafe exim4 spool cleanup path: '.$dir);
            continue;
        }
        runStep('Purging stale exim4 spool files in '.$dir, $command);
    }
}
