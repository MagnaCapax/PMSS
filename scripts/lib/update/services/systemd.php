<?php
/**
 * Stable systemd service include, cron containment and managed restart drop-ins.
 *
 * PMSS runs most user-facing daemons per-user (under /home/<user>) and relies
 * on nginx as the shared front-end. System-wide units for apps like Deluge
 * should remain stopped/disabled to reduce attack surface.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../runtime/commands.php';
require_once __DIR__.'/../managedPath.php';
require_once __DIR__.'/../runtime/processes.php';

require_once __DIR__.'/systemd/guard.php';
require_once __DIR__.'/systemd/timer.php';
require_once __DIR__.'/systemd/resolver.php';

/**
 * Keep cron available because PMSS watchdogs and quota jobs depend on it.
 */
function pmssEnsureCronServiceActive(string $context = 'update'): void
{
    if (pmssSystemdActionSkip(pmssSystemdActionSkipReason('cron.service'), 'Ensuring cron service is active', false)) return;
    if (pmssSystemdUnitState('is-enabled', 'cron.service') === 'masked') {
        logmsg('[WARN] cron.service is masked during '.$context.'; unmasking immediately');
        runStep('Unmasking cron service ('.$context.')', 'systemctl unmask cron.service || true');
    }

    runStep('Enabling and starting cron service ('.$context.')', 'systemctl enable --now cron.service || true');
}

/**
 * Return the PMSS-owned cron.service drop-in payload.
 *
 * @param int|null $cpuThreads Logical CPU thread count; resolved from the host when null.
 *                             Pass an explicit value in tests for deterministic output.
 *
 * Recoverability guarantee (Refs #579): Debian cron runs user crontabs inside
 * cron.service (system.slice), so a runaway user crontab escapes the per-user
 * user-UID.slice limits. A CPUQuota that reserves at least one logical thread for
 * the rest of system.slice (sshd, root recovery) means such a storm can never
 * consume every core and lock root/sshd off the box. PMSS's own light periodic
 * root crons never approach this cap; only a storm does. This bounds the CPU-
 * starvation failure mode while per-user cron isolation (the deeper fix) is
 * developed; the TasksMax cap continues to bound fork/pid damage.
 */
function pmssCronRestartDropinContent(?int $cpuThreads = null): string
{
    if ($cpuThreads === null) {
        $cpuThreads = function_exists('pmssTotalCpuThreads')
            ? (int) pmssTotalCpuThreads()
            : (int) trim((string) @shell_exec('nproc 2>/dev/null'));
    }
    if ($cpuThreads < 1) {
        $cpuThreads = 1;
    }

    // Reserve >=1 thread for system.slice/sshd; only cap when there is headroom to reserve.
    $cronQuotaLine = ($cpuThreads >= 2)
        ? 'CPUQuota='.(($cpuThreads - 1) * 100)."%\n"
        : '';

    return "[Service]\n"
        ."# Debian cron runs user crontabs inside cron.service rather than user-UID.slice.\n"
        ."# Bound aggregate fork damage until per-user cron isolation is proven safe.\n"
        ."# CPUQuota reserves >=1 thread for system.slice/sshd so a user-cron storm cannot\n"
        ."# CPU-starve root/sshd off the box (the recoverability guarantee, Refs #579).\n"
        ."TasksAccounting=yes\n"
        ."TasksMax=8192\n"
        ."CPUAccounting=yes\n"
        .$cronQuotaLine
        ."Restart=always\n"
        ."RestartSec=10\n"
        ."# Restore /etc/cron.d/pmss before cron reads /etc/cron.d, so its @reboot jobs run on\n"
        ."# a boot after an interrupted update (#1180). update.php holds the marker while it\n"
        ."# keeps root cron off on purpose; /run is cleared at boot (#1185).\n"
        ."ExecStartPre=-/bin/sh -c 'test -e ".pmssRootCronDisabledMarker()." || test -e /etc/cron.d/pmss || ! test -f /etc/seedbox/config/root.cron || install -m 0644 /etc/seedbox/config/root.cron /etc/cron.d/pmss'\n";
}

/** Marker update.php holds while it keeps /etc/cron.d/pmss removed on purpose (#1185). */
function pmssRootCronDisabledMarker(): string
{
    return '/run/pmss-root-cron-disabled';
}

/** Restart a failed ProFTPD master after transient startup dependencies recover. */
function pmssProftpdRestartDropinContent(): string
{
    return "[Unit]\nStartLimitIntervalSec=0\n\n[Service]\nRestart=always\nRestartSec=30\n";
}

/**
 * Ensure a service restart policy drop-in is written to a safe target.
 */
function pmssEnsureServiceRestartDropin(
    string $label, string $content, string $dropinDir, string $dropinFile,
    string $systemdRuntimeDir, ?bool &$changed
): bool {
    $changed = false;
    if (!is_dir($systemdRuntimeDir)) {
        return true;
    }

    $dropinDir = rtrim($dropinDir, '/');
    $dropinFile = $dropinFile !== '' ? $dropinFile : $dropinDir.'/pmss-restart.conf';
    if ($dropinDir === '' || $dropinDir[0] !== '/' || $dropinFile[0] !== '/' || dirname($dropinFile) !== $dropinDir) {
        logMessage('[ERR] Refusing unsafe '.$label.' systemd drop-in path: '.$dropinFile);
        return false;
    }
    if (!pmssPathTargetIsSafe($dropinDir, true, false, false)) {
        logMessage('[ERR] Refusing unsafe '.$label.' systemd drop-in directory path: '.$dropinDir);
        return false;
    }

    $changed = pmssRefreshManagedPathFile(
        $dropinFile,
        $content,
        $label.' systemd drop-in',
        'logMessage',
        [
            'directoryFailureMessage' => '[ERR] Failed to create '.$label.' systemd drop-in directory: '.$dropinDir,
            'writeFailureMessage' => '[ERR] Failed to write '.$label.' systemd drop-in target: '.$dropinFile,
        ],
        $succeeded
    );
    if ($changed && ((int) @fileperms($dropinFile) & 0777) !== 0644) {
        logMessage('[ERR] Failed to set '.$label.' systemd drop-in mode: '.$dropinFile);
        $changed = false;
        return false;
    }
    return $succeeded;
}

/** Preserve the cron drop-in's public call and logging contract. */
function pmssEnsureCronRestartDropin(
    string $dropinDir = '/etc/systemd/system/cron.service.d',
    string $dropinFile = '',
    string $systemdRuntimeDir = '/run/systemd/system',
    ?bool &$changed = null
): bool {
    return pmssEnsureServiceRestartDropin('cron', pmssCronRestartDropinContent(), $dropinDir, $dropinFile, $systemdRuntimeDir, $changed);
}

/** Ensure ProFTPD has a retry policy without changing its other unit settings. */
function pmssEnsureProftpdRestartDropin(
    string $dropinDir = '/etc/systemd/system/proftpd.service.d',
    string $dropinFile = '',
    string $systemdRuntimeDir = '/run/systemd/system',
    ?bool &$changed = null
): bool {
    return pmssEnsureServiceRestartDropin('proftpd', pmssProftpdRestartDropinContent(), $dropinDir, $dropinFile, $systemdRuntimeDir, $changed);
}

/** PAM session line that places cron-spawned user jobs into user-UID.slice. */
function pmssCronPamSystemdLine(): string
{
    return "session    optional   pam_systemd.so\n";
}

/**
 * Contain user crontabs in their per-user slice by adding pam_systemd to cron's
 * PAM session stack (Refs #579 A1, operator directive 2026-06-29).
 *
 * Verified live 2026-06-29 (Debian 11, systemd 247, cgroup-v2): without this,
 * every user crontab runs in cron.service (system.slice), escaping the per-user
 * CPUQuota/TasksMax — a user-cron storm then starves the host. pam_systemd
 * registers a logind background session for the job's UID, which places the job
 * tree under user-UID.slice where the per-user limits bind it. The `optional`
 * control means a pam_systemd failure can NEVER block cron job execution
 * (services that run from cron stay safe even if logind is unavailable).
 *
 * Append-only and idempotent; only amends an EXISTING regular /etc/pam.d/cron
 * (never creates it, never follows a symlink). File mode is preserved.
 *
 * @return bool True on success or no-op; false only on an unexpected unsafe state.
 */
function pmssEnsureCronPamSystemdSession(string $pamCronPath = '/etc/pam.d/cron'): bool
{
    if ($pamCronPath === '' || $pamCronPath[0] !== '/') {
        logMessage('[ERR] Refusing unsafe cron PAM path: '.$pamCronPath);
        return false;
    }
    if (!file_exists($pamCronPath)) {
        return true; // no cron PAM file -> nothing to contain
    }
    if (!pmssRegularFilePathIsReadable($pamCronPath)) {
        logMessage('[ERR] Refusing non-regular cron PAM target: '.$pamCronPath);
        return false;
    }
    $content = pmssReadRegularFileContents($pamCronPath);
    if ($content === null) {
        logMessage('[ERR] Failed to read cron PAM file: '.$pamCronPath);
        return false;
    }
    if (preg_match('/^[^#\n]*\bpam_systemd\.so\b/m', $content) === 1) {
        return true; // already present
    }
    $new = rtrim($content, "\n")."\n".pmssCronPamSystemdLine();
    if (!pmssReplaceUserFilePreservingMetadata($pamCronPath, $new)) {
        logMessage('[ERR] Failed to append pam_systemd to cron PAM file: '.$pamCronPath);
        return false;
    }
    return true;
}
