#!/usr/bin/env php
<?php
/**
 * Observe installed per-user media-stack tmux sessions, and kill any *ARR running as root.
 *
 * This task publishes runtime state and stops at reporting repeated failures;
 * it deliberately does not restart an app indefinitely without diagnosis.
 *
 * The root-*ARR kill rides here rather than in its own cron because this is already the
 * root-owned, fleet-wide, every-two-minutes media-stack task -- a second timer for the same
 * concern would be duplication. It is the backstop to the structural block in
 * scripts/lib/update/arrRootExecutionBlock.php; see scripts/lib/arrRootGuard.php.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../lib/mediaStackWatchdog.php';
require_once __DIR__.'/../lib/user/selection.php';
require_once __DIR__.'/../lib/arrRootGuard.php';
require_once __DIR__.'/../lib/runtime.php';

$rootGuardFindings = pmssRootGuardAuditAndKill(static function (string $message): void {
    echo date('Y-m-d H:i:s').': '.$message."\n";
});

// Keep the handle alive while home filesystem probes run so overlapping ticks skip.
$pmssMediaStackInstancesLock = pmssLockFileAcquire(pmssRuntimeLockPath('pmss-mediaStackInstancesCheck.lock'), true);
if ($pmssMediaStackInstancesLock === false) {
    echo date('Y-m-d H:i:s').': mediaStackInstancesCheck already running; skipping'."\n";
    exit($rootGuardFindings > 0 ? 1 : 0);
}

$result = pmssListManagedUsersResult('/scripts/listUsers.php');
if ((int) $result['exitCode'] !== 0) {
    fwrite(STDERR, 'Error: listUsers.php failed; aborting media-stack check.'.PHP_EOL);
    exit((int) $result['exitCode']);
}

$homeRoot = pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home');
foreach ($result['users'] as $username) {
    pmssMediaStackWatchdogRunUser($username, $homeRoot);
}

exit($rootGuardFindings > 0 ? 1 : 0);
