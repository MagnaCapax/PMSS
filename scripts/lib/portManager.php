<?php
/**
 * Shared service-port allocator for PMSS user services.
 *
 * `/scripts/util/portManager.php` is the stable CLI wrapper. Runtime code loads
 * this library directly so shared allocation logic stays out of util wrappers.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/runtime.php';
pmssRequireRelativeFiles(__DIR__, ['lighttpd/userFileWrite.php', 'userLifecycle.php']);

const PMSS_PORT_MANAGER_MIN_PORT = 2000;
const PMSS_PORT_MANAGER_MAX_PORT = 38000;

/** Write a port assignment event to the shared user logs when available. */
function pmssPortManagerLog(string $user, string $action, string $service, ?int $port, string $status, string $message): void
{
    pmssUserWriteLogs(pmssUserBaseContext('port', $action, $user, array('status' => $status, 'service' => $service, 'port' => $port, 'message' => $message)));
}

/** Read a persisted port assignment and reject malformed or out-of-range data. */
function pmssPortManagerReadAssignedPort(string $portFile): ?int
{
    return pmssReadRegularFileNetworkPort($portFile);
}

require_once __DIR__.'/portManager/state.php';
require_once __DIR__.'/portManager/selection.php';
require_once __DIR__.'/portManager/assignment.php';
require_once __DIR__.'/portManager/cli.php';
