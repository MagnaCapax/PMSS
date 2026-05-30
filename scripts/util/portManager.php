#!/usr/bin/env php
<?php
/**
 * Port assignment helper for per-user services.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/userLifecycle.php';

const PMSS_PORT_MANAGER_MIN_PORT = 2000;
const PMSS_PORT_MANAGER_MAX_PORT = 38000;

/**
 * Write a port assignment event to the shared user logs when available.
 */
function pmssPortManagerLog(string $user, string $action, string $service, ?int $port, string $status, string $message): void
{
    pmssUserWriteLogs(pmssUserBaseContext('port', $action, $user, array('status' => $status, 'service' => $service, 'port' => $port, 'message' => $message)));
}

/**
 * Read a persisted port assignment and reject malformed or out-of-range data.
 */
function pmssPortManagerReadAssignedPort(string $portFile): ?int
{
    return pmssReadRegularFileNetworkPort($portFile);
}

/**
 * Guard assignment file reads/writes/removals against symlink and type tricks.
 */
function pmssPortManagerAssignmentPathIsSafe(string $portDir, string $portFile): bool
{
    $portDir = rtrim($portDir, '/');
    if ($portDir === '' || dirname($portFile) !== $portDir) {
        return false;
    }

    return is_dir($portDir)
        && !is_link($portDir)
        && !is_link($portFile)
        && (!file_exists($portFile) || is_file($portFile));
}

/**
 * Pick one free service port without risking an unbounded collision loop.
 *
 * @param array<int, bool> $used
 */
function pmssPortManagerSelectAvailablePort(array $used): ?int
{
    $available = [];
    for ($port = PMSS_PORT_MANAGER_MIN_PORT; $port <= PMSS_PORT_MANAGER_MAX_PORT; $port++) {
        if (!isset($used[$port])) {
            $available[] = $port;
        }
    }

    if ($available === []) {
        return null;
    }

    return $available[rand(0, count($available) - 1)];
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
    // Service names are persisted in filenames, so keep them path-safe.
    if (preg_match('/^[a-z][a-z0-9-]{0,31}$/', $service) !== 1) {
        return pmssPortManagerFail("Error: invalid service\n");
    }

    $portDir = rtrim(pmssResolvePathFromEnv('PMSS_PORT_MANAGER_DIR', '/etc/seedbox/runtime/ports'), '/');
    if (!pmssPathTargetIsSafe($portDir, true)
        || !pmssDirEnsureExists($portDir, 0755)
        || !is_dir($portDir)
        || is_link($portDir)
    ) {
        return pmssPortManagerFail("Error: unable to initialize port directory\n");
    }

    $portFile = $portDir.'/'.$service.'-'.$user;
    $portFilePresent = file_exists($portFile) || is_link($portFile);
    if ($portFilePresent && !pmssPortManagerAssignmentPathIsSafe($portDir, $portFile)) {
        return pmssPortManagerFail("Error: invalid stored port assignment\n", $user, $action, $service, null, 'ERR', 'unsafe_assignment_path');
    }

    $lockHandle = false;
    if ($action !== 'view') {
        $lockHandle = pmssLockFileAcquire(pmssRuntimeLockPath('pmss-portManager-'.$service.'.lock'));
        if ($lockHandle === false) {
            pmssPortManagerLog($user, $action, $service, null, 'WARN', 'lock_failed');
        }
    }

    try {
        if ($action === 'view') {
            if (!$portFilePresent) {
                echo 'No port assigned';
                return 0;
            }
            $assignedPort = pmssPortManagerReadAssignedPort($portFile);
            if ($assignedPort === null) return pmssPortManagerFail("Error: invalid stored port assignment\n");
            echo $assignedPort;
            return 0;
        }

        if ($action === 'assign' && $portFilePresent) {
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
            $port = pmssPortManagerSelectAvailablePort($used);
            if ($port === null) return pmssPortManagerFail("Error: no free port available\n", $user, $action, $service, null, 'ERR', 'port_range_exhausted');
            if (!pmssPortManagerAssignmentPathIsSafe($portDir, $portFile)) return pmssPortManagerFail("Error: invalid port assignment path\n", $user, $action, $service, null, 'ERR', 'unsafe_assignment_path');
            if (@file_put_contents($portFile, $port, LOCK_EX) === false) return pmssPortManagerFail("Error: failed to persist port assignment\n", $user, $action, $service, $port, 'ERR', 'write_failed');
            !@chmod($portFile, 0640) && pmssPortManagerLog($user, $action, $service, $port, 'WARN', 'chmod_failed');
            echo $port;
            pmssPortManagerLog($user, $action, $service, $port, 'OK', 'assigned');
            return 0;
        }

        if (!$portFilePresent) {
            echo 'No port assigned';
            return 0;
        }
        if (!pmssPortManagerAssignmentPathIsSafe($portDir, $portFile)) return pmssPortManagerFail("Error: invalid stored port assignment\n", $user, $action, $service, null, 'ERR', 'unsafe_assignment_path');
        if (!@unlink($portFile)) return pmssPortManagerFail("Error: failed to release port\n", $user, $action, $service, null, 'ERR', 'release_failed');
        echo 'Port released';
        pmssPortManagerLog($user, $action, $service, null, 'OK', 'released');
        return 0;
    } finally {
        if ($lockHandle !== false) pmssLockHandleRelease($lockHandle);
    }
}

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssPortManagerMain');
