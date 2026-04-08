#!/usr/bin/env php
<?php
/**
 * Port assignment helper for per-user services.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/runtime.php';

$lifecyclePath = __DIR__.'/../lib/userLifecycle.php';
if (is_file($lifecyclePath)) {
    require_once $lifecyclePath;
}

const PMSS_PORT_MANAGER_USAGE = 'Usage: portManager.php [view|assign|release] USER [SERVICE]';

/**
 * Service names are persisted in filenames, so keep them path-safe.
 */
function pmssPortManagerServiceIsValid(string $service): bool
{
    return preg_match('/^[a-z][a-z0-9-]{0,31}$/', $service) === 1;
}

/** Username validation falls back to the historic local regex when needed. */
function pmssPortManagerUserIsValid(string $user): bool
{
    return function_exists('pmssValidateUsername')
        ? pmssValidateUsername($user)
        : preg_match('/^[a-z][a-z0-9]{0,7}$/', $user) === 1;
}

/**
 * Write a port assignment event to the shared user logs when available.
 */
function pmssPortManagerLog(string $user, string $action, string $service, ?int $port, string $status, string $message): void
{
    if (!function_exists('pmssUserWriteLogs') || !function_exists('pmssUserBaseContext')) return;
    pmssUserWriteLogs(pmssUserBaseContext('port', $action, $user, array('status' => $status, 'service' => $service, 'port' => $port, 'message' => $message)));
}

/**
 * Read a persisted port assignment and reject malformed or out-of-range data.
 */
function pmssPortManagerReadAssignedPort(string $portFile): ?int
{
    $raw = @file_get_contents($portFile);
    if ($raw === false) return null;
    $port = (int) trim((string) $raw);
    return $port >= 1 && $port <= 65535 ? $port : null;
}

/** Emit the public error text and optionally mirror it to user logs. */
function pmssPortManagerFail(string $message, string $user = '', string $action = '', string $service = '', ?int $port = null, string $status = 'ERR', string $logMessage = ''): int
{
    fwrite(STDERR, $message);
    if ($logMessage !== '' && $user !== '' && $action !== '' && $service !== '') pmssPortManagerLog($user, $action, $service, $port, $status, $logMessage);
    return 1;
}

/** Keep one dispatch path for every CLI action. */
function pmssPortManagerMain(array $argv): int
{
    if (count($argv) < 3) {
        echo PMSS_PORT_MANAGER_USAGE;
        return 0;
    }

    $action = strtolower(trim((string) $argv[1]));
    if (!in_array($action, array('view', 'assign', 'release'), true)) {
        echo PMSS_PORT_MANAGER_USAGE;
        return 0;
    }

    $user = trim((string) $argv[2]);
    if (!pmssPortManagerUserIsValid($user)) {
        return pmssPortManagerFail("Error: invalid username\n");
    }

    $service = isset($argv[3]) ? strtolower(trim((string) $argv[3])) : 'lighttpd';
    if (!pmssPortManagerServiceIsValid($service)) {
        return pmssPortManagerFail("Error: invalid service\n");
    }

    $portDir = pmssResolvePathFromEnv('PMSS_PORT_MANAGER_DIR', '/etc/seedbox/runtime/ports');
    if (!pmssDirEnsureExists($portDir, 0755)) {
        return pmssPortManagerFail("Error: unable to initialize port directory\n");
    }

    $portFile = $portDir.'/'.$service.'-'.$user;

    $lockHandle = false;
    if ($action !== 'view') {
        $lockHandle = pmssLockFileAcquire(pmssRuntimeLockPath('pmss-portManager-'.$service.'.lock'));
        if ($lockHandle === false) {
            pmssPortManagerLog($user, $action, $service, null, 'WARN', 'lock_failed');
        }
    }

    try {
        if ($action === 'view') {
            if (!file_exists($portFile)) {
                echo 'No port assigned';
                return 0;
            }
            $assignedPort = pmssPortManagerReadAssignedPort($portFile);
            if ($assignedPort === null) return pmssPortManagerFail("Error: invalid stored port assignment\n");
            echo $assignedPort;
            return 0;
        }

        if ($action === 'assign' && file_exists($portFile)) {
            $existing = pmssPortManagerReadAssignedPort($portFile);
            if ($existing === null) return pmssPortManagerFail("Error: invalid stored port assignment\n", $user, $action, $service, null, 'ERR', 'invalid_existing_assignment');
            echo $existing;
            pmssPortManagerLog($user, $action, $service, $existing, 'SKIP', 'already_assigned');
            return 0;
        }

        if ($action === 'assign') {
            $used = array();
            foreach ((glob($portDir.'/'.$service.'-*') ?: array()) as $path) {
                $assignedPort = pmssPortManagerReadAssignedPort($path);
                if ($assignedPort !== null) {
                    $used[$assignedPort] = true;
                }
            }
            do {
                $port = rand(2000, 38000);
            } while (isset($used[$port]));
            if (@file_put_contents($portFile, $port, LOCK_EX) === false) return pmssPortManagerFail("Error: failed to persist port assignment\n", $user, $action, $service, $port, 'ERR', 'write_failed');
            !@chmod($portFile, 0640) && pmssPortManagerLog($user, $action, $service, $port, 'WARN', 'chmod_failed');
            echo $port;
            pmssPortManagerLog($user, $action, $service, $port, 'OK', 'assigned');
            return 0;
        }

        if (!file_exists($portFile)) {
            echo 'No port assigned';
            return 0;
        }
        if (!@unlink($portFile)) return pmssPortManagerFail("Error: failed to release port\n", $user, $action, $service, null, 'ERR', 'release_failed');
        echo 'Port released';
        pmssPortManagerLog($user, $action, $service, null, 'OK', 'released');
        return 0;
    } finally {
        if ($lockHandle !== false) pmssLockHandleRelease($lockHandle);
    }
}

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssPortManagerMain');
