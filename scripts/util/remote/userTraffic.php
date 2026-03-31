#!/usr/bin/env php
<?php
/**
 * Utility script: user traffic callback payload for Hallinta automation.
 *
 * Output contract (STDOUT only, no banners/warnings):
 * - Exactly one PHP serialized array: array<string,array{normal:int,local:int,ingress:int}>
 * - Outer key: PMSS username
 * - Empty result set serializes as a:0:{}
 *
 * Consumer contract:
 * - Hallinta callback path expects unserialize()-compatible payload
 * - Any extra STDOUT bytes can break callback parsing
 *
 * Compatibility requirement:
 * - Keep PMSS_SKIP_HOME_MOUNT_CHECK=1 for this read-only callback producer so
 *   historical/non-standard hosts still return payload instead of mount-gate
 *   diagnostics.
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
