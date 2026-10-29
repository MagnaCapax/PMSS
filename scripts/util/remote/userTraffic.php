#!/usr/bin/env php
<?php
/**
 * Utility script: user traffic callback payload for Hallinta automation.
 *
 * Output contract (STDOUT only, no banners/warnings):
 * - Exactly one PHP serialized array: array<string,array{normal:int,local:int,ingress:int}>
 * - Outer key: PMSS username
 * - Empty result set serializes as a:0:{}
 * - When STDOUT is not a TTY, heartbeat newlines may precede the payload while
 *   the traffic files are read, so a slow cold-cache read is not cut off by the
 *   caller's read timeout. The heartbeat stops before the payload is written.
 *
 * Consumer contract:
 * - Hallinta callback path expects an unserialize()-compatible payload after trim()
 * - Leading newlines are allowed; any other STDOUT bytes, and anything written
 *   after the payload, can break callback parsing
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

require_once dirname(__DIR__, 2).'/lib/runtime.php';
require_once dirname(__DIR__, 2).'/lib/runtime/stdoutHeartbeat.php';
require_once dirname(__DIR__, 2).'/lib/user/selection.php';
require_once dirname(__DIR__, 2).'/lib/user/traffic.php';

pmssStdoutHeartbeatStartForStdout();

$userTrafficData = [];
foreach (pmssManagedHomeUsersList() as $userName) {
    $userTrafficData[$userName] = pmssReadUserTrafficStates($userName);
}

// Stop and reap the heartbeat first so no newline can land inside or after the payload.
pmssStdoutHeartbeatStop();
echo serialize($userTrafficData);
