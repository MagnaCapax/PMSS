<?php
/**
 * Hardening of a tenant's view of the host's runtime socket state.
 *
 * On a shared host all accounts share one network namespace, so the kernel's
 * address-bearing /proc/net tables (and the saved TCP metrics cache) let one
 * account read another account's remote connection addresses. hidepid=2 and the
 * who/w/utmp/netstat mode hardening do not reach these kernel sources.
 *
 * This applier restricts the address-bearing /proc/net tables to root and stops
 * the kernel from persisting the per-peer TCP metrics cache, on every host (ADR
 * 0068, default since 2026-11-17). Root and PMSS tooling are unaffected.
 *
 * The /proc/net modes are per-network-namespace kernel state that resets on
 * reboot; template.pmss-boot-tuning.sh reapplies them at boot. This module is
 * the update-time applier and the single source of the table list.
 *
 * The `ss` / NETLINK_SOCK_DIAG channel is handled by the BPF-LSM filter in bpf/
 * (stage 2), loaded by bpf/socket-privacy-load.sh, which fails open.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../managedPath.php';
require_once __DIR__.'/../../pathSafety.php';

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
 * Does the installed bpftool support `prog loadall ... autoattach` (needed to attach
 * the LSM program)? Debian 12 main ships bpftool 7.1.0, which lacks it entirely (no
 * autoattach keyword, no `link create`, no `lsm` in `prog attach`); bpftool >= 7.5
 * (bookworm-backports, Debian 13 main) has it. Detected from `prog help`, not a
 * version string, so the check tracks the actual capability.
 */
function pmssSocketTablePrivacyBpftoolHasAutoattach(): bool
{
    if (trim((string) @shell_exec('command -v bpftool 2>/dev/null')) === '') {
        return false;
    }
    $help = (string) @shell_exec('bpftool prog help 2>&1');
    return strpos($help, 'autoattach') !== false;
}

/**
 * Ensure an autoattach-capable bpftool is present.
 *
 * Stage 2 (the sock_diag BPF-LSM filter) can only be attached by bpftool >= 7.5.
 * On Debian 12 that means bookworm-backports; elsewhere the main archive already
 * carries a new enough build. Runs at update time only (never at boot — apt must
 * not run from the boot loader). Fail-soft: if a capable bpftool cannot be
 * installed the loader fails open and the host runs stage 1 only.
 *
 * Returns true when an autoattach-capable bpftool is present after this call.
 */
function pmssSocketTablePrivacyEnsureBpftool(?callable $logger = null, ?int $distroVersion = null): bool
{
    $log = $logger ?: 'logMessage';
    if (pmssSocketTablePrivacyBpftoolHasAutoattach()) {
        return true;
    }
    if ($distroVersion === null && function_exists('pmssDetectDistro')) {
        $distroVersion = (int) (pmssDetectDistro()['version'] ?? 0);
    }
    if (function_exists('runStep')) {
        if ((int) $distroVersion === 12) {
            runStep('Installing autoattach-capable bpftool (bookworm-backports)', 'apt-get -o Dpkg::Use-Pty=0 -y -t bookworm-backports install bpftool >/dev/null 2>&1 || true');
        } else {
            runStep('Installing bpftool for socket-table privacy', 'apt-get -o Dpkg::Use-Pty=0 -y install bpftool >/dev/null 2>&1 || true');
        }
    }
    $ok = pmssSocketTablePrivacyBpftoolHasAutoattach();
    $log($ok
        ? 'Socket-table privacy: autoattach-capable bpftool present'
        : '[WARN] Socket-table privacy: no autoattach-capable bpftool available; stage-2 filter will fail open (stage 1 still applied)');
    return $ok;
}

/**
 * Set every existing address-bearing /proc/net table to $mode.
 * 0440 hides remote addresses from non-root. Returns the count of tables changed.
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
        if (@chmod($path, $mode)) {
            $changed++;
        }
    }
    return $changed;
}

/**
 * Apply the feature: /proc/net tables 0440 + sysctl drop-in
 * (tcp_no_metrics_save=1) + flush the existing metrics cache.
 *
 * Idempotent and side-effect-scoped: it never touches anything but the tables,
 * the one drop-in, and the metrics cache. Returns a status array for logging.
 */
function pmssSocketTablePrivacyApply(?callable $logger = null, ?string $procNetRoot = null, ?string $sysctlDropin = null): array
{
    $log = $logger ?: 'logMessage';
    $sysctlDropin = $sysctlDropin ?? pmssSocketTablePrivacySysctlDropinPath();

    $hidden = pmssSocketTablePrivacyChmodProcNet(0440, $procNetRoot);
    $content = "# PMSS socket-table privacy: stop persisting the per-peer TCP metrics cache.\n# Managed by scripts/lib/update/systemPrep/socketTablePrivacy.php (ADR 0068).\nnet.ipv4.tcp_no_metrics_save = 1\n";
    $metrics = 'unchanged';
    if (pmssInstallManagedPathFile($sysctlDropin, $content, 'Socket-table privacy sysctl drop-in', $log, ['mode' => 0644])) {
        $metrics = 'written';
    }
    if ($metrics === 'written' && function_exists('runStep')) {
        runStep('Enabling TCP metrics privacy sysctl', 'sysctl -q -w net.ipv4.tcp_no_metrics_save=1 || true');
        runStep('Flushing saved TCP metrics cache', 'ip tcp_metrics flush all 2>/dev/null || true');
    }
    $log('Socket-table privacy: '.$hidden.' /proc/net tables restricted to root, TCP metrics persistence off ('.$metrics.')');
    return ['tables_changed' => $hidden, 'metrics' => $metrics];
}
