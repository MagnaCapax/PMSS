#!/usr/bin/env php
<?php
/**
 * Observe installed per-user media-stack tmux sessions.
 *
 * This task publishes runtime state and stops at reporting repeated failures;
 * it deliberately does not restart an app indefinitely without diagnosis.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../lib/mediaStackWatchdog.php';
require_once __DIR__.'/../lib/user/selection.php';

echo date('Y-m-d H:i:s').": Checking media stack instances\n";
$result = pmssListManagedUsersResult('/scripts/listUsers.php');
if ((int) $result['exitCode'] !== 0) {
    fwrite(STDERR, 'Error: listUsers.php failed; aborting media-stack check.'.PHP_EOL);
    exit((int) $result['exitCode']);
}

$homeRoot = pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home');
foreach ($result['users'] as $username) {
    pmssMediaStackWatchdogRunUser($username, $homeRoot);
}
