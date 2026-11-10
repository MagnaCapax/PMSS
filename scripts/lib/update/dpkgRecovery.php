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
 * Finish any interrupted dpkg configuration runs.
 */
function pmssCompletePendingDpkg(): void
{
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
