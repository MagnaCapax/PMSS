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
