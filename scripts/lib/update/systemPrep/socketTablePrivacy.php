<?php
/**
 * Opt-in hardening of a tenant's view of the host's runtime socket state.
 *
 * On a shared host all accounts share one network namespace, so the kernel's
 * address-bearing /proc/net tables (and the saved TCP metrics cache) let one
 * account read another account's remote connection addresses. hidepid=2 and the
 * who/w/utmp/netstat mode hardening do not reach these kernel sources.
 *
 * When the operator places the marker file, this applier restricts the
 * address-bearing /proc/net tables to root and stops the kernel from persisting
 * the per-peer TCP metrics cache. Root and PMSS tooling are unaffected. With no
 * marker (the default) it restores the kernel's stock 0444 mode and removes the
 * sysctl drop-in, so the feature is inert until explicitly enabled.
 *
 * The /proc/net modes are per-network-namespace kernel state that resets on
 * reboot; template.pmss-boot-tuning.sh reapplies them at boot. This module is
 * the update-time applier and the single source of the marker path + table list.
 *
 * The `ss` / NETLINK_SOCK_DIAG channel is not closed here (a kernel-side filter
 * is required and is tracked separately); enabling this module alone reduces,
 * but does not eliminate, cross-account visibility of remote addresses.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../managedPath.php';
require_once __DIR__.'/../../pathSafety.php';

/** Operator-set marker; its presence enables the feature on this host. */
function pmssSocketTablePrivacyMarkerPath(): string
{
    return pmssResolvePathFromEnv('PMSS_SOCKET_TABLE_PRIVACY_MARKER', '/etc/seedbox/config/socket-table-privacy.enabled');
}

/** True when the operator has enabled the feature on this host. */
function pmssSocketTablePrivacyEnabled(?string $markerPath = null): bool
{
    return is_file($markerPath ?? pmssSocketTablePrivacyMarkerPath());
}

/** Address-bearing /proc/net tables, relative to the proc-net root. */
function pmssSocketTablePrivacyTables(): array
{
    return ['tcp', 'tcp6', 'udp', 'udp6', 'udplite', 'udplite6', 'raw', 'raw6', 'icmp', 'icmp6'];
}

/** sysctl drop-in path that stops the kernel persisting the TCP metrics cache. */
function pmssSocketTablePrivacySysctlDropinPath(): string
{
    return pmssResolvePathFromEnv('PMSS_SOCKET_TABLE_PRIVACY_SYSCTL', '/etc/sysctl.d/91-pmss-socket-table-privacy.conf');
}

/**
 * Set every existing address-bearing /proc/net table to $mode.
 * 0440 hides remote addresses from non-root; 0444 is the kernel's stock mode.
 * Returns the count of tables actually changed.
 *
 * The restore direction (0444) is deliberately NON-WIDENING: it only touches a
 * table currently at exactly 0440 (this feature's own signature mode). A table
 * left at any other mode by the kernel or a future hardening layer is not
 * loosened back to 0444 — so disabling this feature can never widen access
 * beyond what it originally restricted.
 */
function pmssSocketTablePrivacyChmodProcNet(int $mode, ?string $procNetRoot = null): int
{
    $procNetRoot = $procNetRoot ?? pmssResolvePathFromEnv('PMSS_PROC_NET_ROOT', '/proc/net');
    $changed = 0;
    foreach (pmssSocketTablePrivacyTables() as $table) {
        $path = $procNetRoot.'/'.$table;
        if (!file_exists($path)) {
            continue;
        }
        $current = @fileperms($path);
        if (!is_int($current)) {
            continue;
        }
        $current &= 0777;
        if ($current === $mode) {
            continue;
        }
        // Restore (widen to 0444) ONLY our own 0440 mark; never loosen a table
        // the kernel or another layer set more restrictively.
        if ($mode === 0444 && $current !== 0440) {
            continue;
        }
        if (@chmod($path, $mode)) {
            $changed++;
        }
    }
    return $changed;
}

/**
 * Apply the feature when enabled, or restore stock state when disabled.
 *
 * Enabled  -> /proc/net tables 0440 + sysctl drop-in (tcp_no_metrics_save=1) +
 *             flush the existing metrics cache.
 * Disabled -> /proc/net tables restored to 0444 + drop-in removed.
 *
 * Idempotent and side-effect-scoped: it never touches anything but the tables,
 * the one drop-in, and the metrics cache. Returns a status array for logging.
 */
function pmssSocketTablePrivacyApply(?callable $logger = null, ?string $markerPath = null, ?string $procNetRoot = null, ?string $sysctlDropin = null): array
{
    $log = $logger ?: 'logMessage';
    $enabled = pmssSocketTablePrivacyEnabled($markerPath);
    $sysctlDropin = $sysctlDropin ?? pmssSocketTablePrivacySysctlDropinPath();

    if (!$enabled) {
        $restored = pmssSocketTablePrivacyChmodProcNet(0444, $procNetRoot);
        $removed = false;
        if (is_file($sysctlDropin)) {
            $removed = @unlink($sysctlDropin);
        }
        if ($restored > 0 || $removed) {
            $log('Socket-table privacy disabled: restored '.$restored.' /proc/net tables to stock mode'.($removed ? ' and removed the metrics drop-in' : ''));
        }
        return ['enabled' => false, 'tables_changed' => $restored, 'metrics' => $removed ? 'removed' : 'unchanged'];
    }

    $hidden = pmssSocketTablePrivacyChmodProcNet(0440, $procNetRoot);
    $content = "# PMSS opt-in socket-table privacy: stop persisting the per-peer TCP metrics cache.\n# Managed by scripts/lib/update/systemPrep/socketTablePrivacy.php; remove the marker to revert.\nnet.ipv4.tcp_no_metrics_save = 1\n";
    $metrics = 'unchanged';
    if (pmssRefreshManagedPathFile($sysctlDropin, $content, 'Socket-table privacy sysctl drop-in', $log, pmssManagedPathInstallOptions($sysctlDropin, 'Socket-table privacy sysctl drop-in', ['mode' => 0644]))) {
        $metrics = 'written';
    }
    if ($metrics === 'written' && function_exists('runStep')) {
        runStep('Enabling TCP metrics privacy sysctl', 'sysctl -q -w net.ipv4.tcp_no_metrics_save=1 || true');
        runStep('Flushing saved TCP metrics cache', 'ip tcp_metrics flush all 2>/dev/null || true');
    }
    $log('Socket-table privacy enabled: '.$hidden.' /proc/net tables restricted to root, TCP metrics persistence off ('.$metrics.')');
    return ['enabled' => true, 'tables_changed' => $hidden, 'metrics' => $metrics];
}
