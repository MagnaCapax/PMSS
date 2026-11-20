<?php
/**
 * Restore interrupted dpkg state before retrying package baseline work.
 *
 * ADR 0035 still owns process-group termination. Lock release can lag that
 * termination, so recovery must wait before launching another package writer.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/dpkgLocks.php';

/** Complete interrupted configuration only after package locks have cleared. */
function pmssPrepareDpkgRecovery(): bool
{
    if (!pmssWaitForDpkgLocks(60, 1, 'package recovery')) {
        return false;
    }
    pmssCompletePendingDpkg();
    // Configuration can itself time out; do not race its dying process group.
    return pmssWaitForDpkgLocks(60, 1, 'package recovery');
}

/** Repair dependencies, recovering interrupted dpkg again if apt itself fails. */
function pmssRecoverDpkgBaseline(string $description): bool
{
    if (!pmssPrepareDpkgRecovery()) {
        return false;
    }
    return runStep($description, aptCmd('--fix-broken install -y')) === 0
        || pmssPrepareDpkgRecovery();
}

/**
 * Packages dpkg flags "reinstall required" (third status letter R, e.g. iUR after an unpack was killed).
 * Neither `dpkg --configure -a` nor `apt-get --fix-broken install` clears that flag: dpkg answers
 * "reinstall it before attempting configuration" and every later package step fails.
 */
function pmssDpkgReinstallRequiredPackages(): array
{
    $out = (string) shell_exec("dpkg-query -W -f='\${db:Status-Abbrev} \${Package}\\n' 2>/dev/null");
    $packages = [];
    foreach (explode("\n", $out) as $line) {
        // trim() must not turn a corrupt observation into a package reinstall request.
        if (strpos($line, "\0") !== false) {
            continue;
        }
        $parts = preg_split('/\s+/', trim($line));
        if (count($parts) === 2 && strlen($parts[0]) === 3 && $parts[0][2] === 'R' && preg_match('/^[a-z0-9][a-z0-9+.:-]*$/', $parts[1])) {
            $packages[] = $parts[1];
        }
    }
    return $packages;
}

/** Reinstall packages left in reinstall-required state so configuration can finish. */
function pmssReinstallRequiredPackages(): void
{
    $packages = pmssDpkgReinstallRequiredPackages();
    if ($packages !== []) {
        runStep('Reinstalling packages dpkg marks reinstall-required', aptCmd('install --reinstall -y '.implode(' ', array_map('escapeshellarg', $packages))));
    }
}

/**
 * Finish any interrupted dpkg configuration runs.
 */
function pmssCompletePendingDpkg(): void
{
    pmssReinstallRequiredPackages();
    // #TODO replace special-casing with a generic unit-unmask helper when more services require it.
    $hasSystemd = pmssSystemdRuntimeAvailable();
    if ($hasSystemd && pmssSystemdUnitState('is-enabled', 'proftpd.service') === 'masked') {
        runCommand('systemctl unmask proftpd.service');
    }

    $rc = runStep('Completing pending dpkg configuration', dpkgCmd('--force-confdef --force-confold --configure -a'));
    if ($rc === 0 || !pmssWaitForDpkgLocks(60, 1, 'package recovery')) {
        return;
    }
    if ($hasSystemd) {
        runStep('Unmasking proftpd for dpkg retry', 'systemctl unmask proftpd.service || true');
    }
    runStep('Retrying proftpd configure', dpkgCmd('--force-confdef --force-confold --configure proftpd-core proftpd-mod-crypto proftpd-mod-wrap proftpd-basic').' || true');
}
