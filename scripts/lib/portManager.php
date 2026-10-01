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

/** Emit the public error text and optionally mirror it to user logs. */
function pmssPortManagerFail(string $message, string $user = '', string $action = '', string $service = '', ?int $port = null, string $logMessage = ''): int
{
    fwrite(STDERR, $message);
    if ($logMessage !== '' && $user !== '' && $action !== '' && $service !== '') pmssPortManagerLog($user, $action, $service, $port, 'ERR', $logMessage);
    return 1;
}

/** Keep one dispatch path for every CLI action. */
function pmssPortManagerMain(array $argv): int
{
    $usage = 'Usage: portManager.php [view|assign|release] USER [SERVICE]';
    if (count($argv) < 3) {
        echo $usage;
        return 0;
    }

    $action = strtolower(trim((string) $argv[1]));
    if (!in_array($action, array('view', 'assign', 'release'), true)) {
        echo $usage;
        return 0;
    }

    $user = trim((string) $argv[2]);
    if (!pmssValidateUsername($user)) {
        return pmssPortManagerFail("Error: invalid username\n");
    }

    $service = isset($argv[3]) ? strtolower(trim((string) $argv[3])) : 'lighttpd';
    if (!pmssPortManagerServiceNameIsValid($service)) {
        return pmssPortManagerFail("Error: invalid service\n");
    }

    if ($action === 'assign') {
        $assignStatus = '';
        $port = pmssPortManagerAssignServicePort($user, $service, null, $assignStatus);
        if ($port !== null) {
            echo $port;
            pmssPortManagerLog($user, $action, $service, $port, $assignStatus === 'already_assigned' ? 'SKIP' : 'OK', $assignStatus);
            return 0;
        }
        if ($assignStatus === 'port_dir_unavailable') return pmssPortManagerFail("Error: unable to initialize port directory\n");
        if ($assignStatus === 'port_range_exhausted') return pmssPortManagerFail("Error: no free port available\n", $user, $action, $service, null, 'port_range_exhausted');
        if ($assignStatus === 'write_failed') return pmssPortManagerFail("Error: failed to persist port assignment\n", $user, $action, $service, null, 'write_failed');
        return pmssPortManagerFail("Error: invalid stored port assignment\n", $user, $action, $service, null, $assignStatus === 'invalid_existing_assignment' ? 'invalid_existing_assignment' : 'unsafe_assignment_path');
    }

    $context = pmssPortManagerAssignmentContext($user, $service, $contextStatus);
    if ($context === null) {
        if ($contextStatus === 'port_dir_unavailable') return pmssPortManagerFail("Error: unable to initialize port directory\n");
        return pmssPortManagerFail("Error: invalid stored port assignment\n", $user, $action, $service, null, 'unsafe_assignment_path');
    }

    if ($action === 'view') {
        if (!$context['present']) {
            echo 'No port assigned';
            return 0;
        }
        $assignedPort = pmssPortManagerReadAssignedPort($context['file']);
        if ($assignedPort === null) return pmssPortManagerFail("Error: invalid stored port assignment\n");
        echo $assignedPort;
        return 0;
    }

    $lockHandle = pmssLockFileAcquire(pmssRuntimeLockPath('pmss-portManager.lock'));
    if ($lockHandle === false) pmssPortManagerLog($user, $action, $service, null, 'WARN', 'lock_failed');
    try {
        if (!$context['present']) {
            echo 'No port assigned';
            return 0;
        }
        if (!pmssPortManagerAssignmentPathIsSafe($context['dir'], $context['file'])) return pmssPortManagerFail("Error: invalid stored port assignment\n", $user, $action, $service, null, 'unsafe_assignment_path');
        if (!@unlink($context['file'])) return pmssPortManagerFail("Error: failed to release port\n", $user, $action, $service, null, 'release_failed');
        echo 'Port released';
        pmssPortManagerLog($user, $action, $service, null, 'OK', 'released');
        return 0;
    } finally {
        if ($lockHandle !== false) pmssLockHandleRelease($lockHandle);
    }
}
