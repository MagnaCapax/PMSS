#!/usr/bin/env php
<?php
/**
 * Port assignment helper for per-user services.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/../lib/runtime.php';

$usage = "Usage: portManager.php [view|assign|release] USER [SERVICE]\n";
if ($argc < 3) die($usage);

/**
 * Service names are persisted in filenames, so keep them path-safe.
 */
function pmssPortManagerServiceIsValid(string $service): bool
{
    return preg_match('/^[a-z][a-z0-9-]{0,31}$/', $service) === 1;
}

$action = strtolower(trim($argv[1]));
$user = trim($argv[2]);
$service = isset($argv[3]) ? strtolower(trim($argv[3])) : 'lighttpd';

$lifecyclePath = __DIR__.'/../lib/userLifecycle.php';
if (is_file($lifecyclePath)) {
    require_once $lifecyclePath;
}

if (!in_array($action, ['view', 'assign', 'release'], true)) {
    die($usage);
}

if ((function_exists('pmssValidateUsername') && !pmssValidateUsername($user))
    || (!function_exists('pmssValidateUsername') && preg_match('/^[a-z][a-z0-9]{0,7}$/', $user) !== 1)) {
    fwrite(STDERR, "Error: invalid username\n");
    exit(1);
}

if (!pmssPortManagerServiceIsValid($service)) {
    fwrite(STDERR, "Error: invalid service\n");
    exit(1);
}

$portDir = pmssResolvePathFromEnv('PMSS_PORT_MANAGER_DIR', '/etc/seedbox/runtime/ports');
if (!pmssDirEnsureExists($portDir, 0755)) {
    fwrite(STDERR, "Error: unable to initialize port directory\n");
    exit(1);
}

$portFile = "$portDir/{$service}-{$user}";

/**
 * Write a port assignment event to the shared user logs when available.
 */
function pmssPortManagerLog(string $user, string $action, string $service, ?int $port, string $status, string $message): void
{
    if (!function_exists('pmssUserWriteLogs') || !function_exists('pmssUserBaseContext')) {
        return;
    }

    pmssUserWriteLogs(
        pmssUserBaseContext(
            'port',
            $action,
            $user,
            array(
                'status'  => $status,
                'service' => $service,
                'port'    => $port,
                'message' => $message,
            )
        )
    );
}

/**
 * Read a persisted port assignment and reject malformed or out-of-range data.
 */
function pmssPortManagerReadAssignedPort(string $portFile): ?int
{
    $raw = @file_get_contents($portFile);
    if ($raw === false) {
        return null;
    }

    $port = (int) trim((string) $raw);
    return $port >= 1 && $port <= 65535 ? $port : null;
}

$lockHandle = false;
if ($action === 'assign' || $action === 'release') {
    $lockHandle = pmssLockFileAcquire(pmssRuntimeLockPath('pmss-portManager-'.$service.'.lock'));
    if ($lockHandle === false) {
        pmssPortManagerLog($user, $action, $service, null, 'WARN', 'lock_failed');
    }
}

switch ($action) {
    case 'view':
        if (file_exists($portFile)) {
            $assignedPort = pmssPortManagerReadAssignedPort($portFile);
            if ($assignedPort === null) {
                fwrite(STDERR, "Error: invalid stored port assignment\n");
                exit(1);
            }
            echo $assignedPort . "\n";
        } else echo "No port assigned\n";
        break;

    case 'assign':
        if (file_exists($portFile)) {
            $existing = pmssPortManagerReadAssignedPort($portFile);
            if ($existing === null) {
                fwrite(STDERR, "Error: invalid stored port assignment\n");
                pmssPortManagerLog($user, 'assign', $service, null, 'ERR', 'invalid_existing_assignment');
                exit(1);
            }
            echo $existing . "\n";
            pmssPortManagerLog($user, 'assign', $service, $existing, 'SKIP', 'already_assigned');
            break;
        }
        $used = [];
        foreach ((glob("$portDir/{$service}-*") ?: []) as $f) {
            $assignedPort = pmssPortManagerReadAssignedPort($f);
            if ($assignedPort !== null) {
                $used[$assignedPort] = true;
            }
        }
        do {
            $port = rand(2000, 38000);
        } while (isset($used[$port]));
        if (@file_put_contents($portFile, $port, LOCK_EX) === false) {
            fwrite(STDERR, "Error: failed to persist port assignment\n");
            pmssPortManagerLog($user, 'assign', $service, $port, 'ERR', 'write_failed');
            exit(1);
        }
        if (!@chmod($portFile, 0640)) {
            pmssPortManagerLog($user, 'assign', $service, $port, 'WARN', 'chmod_failed');
        }
        echo $port . "\n";
        pmssPortManagerLog($user, 'assign', $service, $port, 'OK', 'assigned');
        break;

    case 'release':
        if (file_exists($portFile)) {
            if (!@unlink($portFile)) {
                fwrite(STDERR, "Error: failed to release port\n");
                pmssPortManagerLog($user, 'release', $service, null, 'ERR', 'release_failed');
                exit(1);
            }
            echo "Port released\n";
            pmssPortManagerLog($user, 'release', $service, null, 'OK', 'released');
        } else echo "No port assigned\n";
        break;
    default:
        die($usage);
}

if ($lockHandle !== false) {
    pmssLockHandleRelease($lockHandle);
}
?>
