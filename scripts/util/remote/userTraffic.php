#!/usr/bin/env php
<?php
/**
 * Utility script: user Traffic.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2).'/lib/userLifecycle.php';
require_once dirname(__DIR__, 2).'/lib/user/traffic.php';

// Legacy Hallinta callback integration expects a clean serialized payload.
// This read-only telemetry command must keep working on historical installs
// where /home is not a separate mount.
putenv('PMSS_SKIP_HOME_MOUNT_CHECK=1');

$userTrafficData = [];
foreach (pmssListManagedUsers('/scripts/listUsers.php') as $userName) {
    $userTrafficData[$userName] = pmssReadUserTrafficStates($userName);
}

echo serialize($userTrafficData);
