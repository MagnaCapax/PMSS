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
 * Implementation rule:
 * - Do not call /scripts/listUsers.php from this callback producer.
 *   listUsers has operational safety gates and human diagnostics that are
 *   valid for operations but can contaminate machine callback payloads.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2).'/lib/users.php';
require_once dirname(__DIR__, 2).'/lib/userLifecycle.php';
require_once dirname(__DIR__, 2).'/lib/user/traffic.php';

$userTrafficData = [];
foreach (users::listHomeUsers() as $userName) {
    if (!pmssValidateUsername($userName)) {
        continue;
    }
    $userTrafficData[$userName] = pmssReadUserTrafficStates($userName);
}

echo serialize($userTrafficData);
