#!/usr/bin/env php
<?php
/**
 * Nightly quota refresher.
 *
 * - Requires `quota` utilities and filesystem support for per-user quotas.
 * - Runs `quota -u <user> -v -s` for every tenant, storing the human-readable output
 *   in `/home/<user>/.quota` for support tooling.
 * - Logs failures via `Logger`, then continues to the next user so one broken
 *   account does not halt the sweep.
 * - Bounds each `quota` call and stops the sweep at the first one that times out,
 *   since that call is hung and the next one would hang the same way (GH #1183).
 *
 * @author    Aleksi Ursin <123067457+MagnaCapax@users.noreply.github.com>
 * @copyright 2010-2025 Magna Capax Finland Oy
 *
 * @license GPL-3.0-only
 */
require_once '/scripts/lib/logger.php';
require_once __DIR__.'/../lib/quota/snapshotRefresh.php';
$logger = new Logger(__FILE__);

// Serialize runs with the canonical in-script lock (ADR-0049), replacing the
// former root.cron `flock -xn`. Overlapping runs would race per-user quota
// snapshot writes and stack on a hung mount (GH #850/#853). Non-blocking: skip
// if another run holds the lock. Hold the handle until exit.
$updateQuotasLock = pmssCronLockAcquire('updateQuotas');
// A quota call stuck in the kernel outlives its kill; it must not keep holding this lock.
pmssLockHandleExportChildCloseFds($updateQuotasLock);

$logger->msg('Updating quota information');
$users = pmssListManagedUsers('/scripts/listUsers.php');

pmssQuotaSweepRun($users, static function (string $user) use ($logger): bool {
    return pmssQuotaSnapshotRefreshUser($user, array($logger, 'msg'));
}, array($logger, 'msg'));
