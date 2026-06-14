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

/** Keep test-mode allocator state under the shared hermetic test root. */
function pmssPortManagerDefaultPath(string $leaf, string $productionPath): string
{
    if (getenv('PMSS_TEST_MODE') !== '1') {
        return $productionPath;
    }
    $testRoot = getenv('PMSS_TEST_TEMP_ROOT');
    $testRoot = is_string($testRoot) && $testRoot !== '' ? rtrim($testRoot, '/') : sys_get_temp_dir();
    return $testRoot.'/'.$leaf;
}

/** Resolve and initialize the shared service-port reservation directory. */
function pmssPortManagerReservationDir(): ?string
{
    $portDir = rtrim(pmssResolvePathFromEnv('PMSS_PORT_MANAGER_DIR', pmssPortManagerDefaultPath('port-manager', '/etc/seedbox/runtime/ports')), '/');
    if (!pmssPathTargetIsSafe($portDir, true) || !pmssDirEnsureExists($portDir, 0755) || !is_dir($portDir) || is_link($portDir)) {
        return null;
    }
    return $portDir;
}

/** Service names are persisted in filenames, so keep them path-safe. */
function pmssPortManagerServiceNameIsValid(string $service): bool
{
    return preg_match('/^[a-z][a-z0-9-]{0,31}$/', $service) === 1;
}

/** Resolve the legacy rTorrent reservation root used for collision avoidance. */
function pmssPortManagerLegacyReservationDir(): string
{
    return rtrim(pmssResolvePathFromEnv('PMSS_PORT_MANAGER_LEGACY_DIR', pmssPortManagerDefaultPath('legacy-rtorrent-ports', '/var/lib/pmss/ports')), '/');
}

/** Guard assignment file reads/writes/removals against symlink and type tricks. */
function pmssPortManagerAssignmentPathIsSafe(string $portDir, string $portFile): bool
{
    $portDir = rtrim($portDir, '/');
    if ($portDir === '' || dirname($portFile) !== $portDir) {
        return false;
    }
    return is_dir($portDir) && !is_link($portDir) && !is_link($portFile) && (!file_exists($portFile) || is_file($portFile));
}

/**
 * Resolve one managed assignment target and classify unsafe existing files.
 *
 * @return array{dir:string,file:string,present:bool}|null
 */
function pmssPortManagerAssignmentContext(string $user, string $service, ?string &$status = null): ?array
{
    $status = 'ok';
    $portDir = pmssPortManagerReservationDir();
    if ($portDir === null) {
        $status = 'port_dir_unavailable';
        return null;
    }
    $portFile = $portDir.'/'.$service.'-'.$user;
    $present = file_exists($portFile) || is_link($portFile);
    if ($present && !pmssPortManagerAssignmentPathIsSafe($portDir, $portFile)) {
        $status = 'unsafe_existing_assignment';
        return null;
    }
    return ['dir' => $portDir, 'file' => $portFile, 'present' => $present];
}

/** Persist one assignment through the shared symlink-safe port writer. */
function pmssPortManagerWriteAssignedPort(string $portDir, string $portFile, int $port): bool
{
    return pmssPortManagerAssignmentPathIsSafe($portDir, $portFile)
        && pmssNetworkPortFileWrite($portFile, $port, PMSS_PORT_MANAGER_MIN_PORT, PMSS_PORT_MANAGER_MAX_PORT, 0640)
        && pmssPortManagerAssignmentPathIsSafe($portDir, $portFile)
        && pmssPortManagerReadAssignedPort($portFile) === $port;
}

/** @return array<int, bool> */
function pmssPortManagerLegacyUsedPorts(string $portsBase = '/var/lib/pmss/ports'): array
{
    $portsBase = rtrim($portsBase, '/');
    if ($portsBase === '' || strpos($portsBase, "\0") !== false || !is_dir($portsBase) || is_link($portsBase)) {
        return [];
    }

    $used = array();
    foreach ((glob($portsBase.'/*/*') ?: array()) as $path) {
        $port = pmssNetworkPortParseDigits(basename($path));
        if ($port !== null) {
            $used[$port] = true;
        }
    }
    return $used;
}

/**
 * Return all ports already reserved in the shared service-port namespace.
 *
 * @return array<int, bool>
 */
function pmssPortManagerUsedPorts(string $portDir, string $legacyPortsBase = '/var/lib/pmss/ports'): array
{
    $used = pmssPortManagerLegacyUsedPorts($legacyPortsBase);
    $portDir = rtrim($portDir, '/');
    if ($portDir === '' || strpos($portDir, "\0") !== false || !is_dir($portDir) || is_link($portDir)) {
        return $used;
    }

    foreach ((glob($portDir.'/*') ?: array()) as $path) {
        $assignedPort = pmssPortManagerReadAssignedPort($path);
        if ($assignedPort !== null) {
            $used[$assignedPort] = true;
        }
    }
    return $used;
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
    return $available === [] ? null : $available[rand(0, count($available) - 1)];
}

/**
 * Assign one managed service port, optionally adopting an existing safe port.
 *
 * @param string|null $status
 */
function pmssPortManagerAssignServicePort(string $user, string $service, ?int $preferredPort = null, &$status = null): ?int
{
    $status = 'invalid_request';
    if (!pmssValidateUsername($user) || !pmssPortManagerServiceNameIsValid($service)) {
        return null;
    }

    $context = pmssPortManagerAssignmentContext($user, $service, $status);
    if ($context === null) {
        return null;
    }

    $lockHandle = pmssLockFileAcquire(pmssRuntimeLockPath('pmss-portManager.lock'));
    try {
        if ($context['present']) {
            $port = pmssPortManagerReadAssignedPort($context['file']);
            if ($port === null) { $status = 'invalid_existing_assignment'; return null; }
            $status = 'already_assigned';
            return $port;
        }

        $used = pmssPortManagerUsedPorts($context['dir'], pmssPortManagerLegacyReservationDir());
        $port = ($preferredPort !== null
            && pmssNetworkPortInRange($preferredPort, PMSS_PORT_MANAGER_MIN_PORT, PMSS_PORT_MANAGER_MAX_PORT)
            && !isset($used[$preferredPort]))
            ? $preferredPort
            : pmssPortManagerSelectAvailablePort($used);
        if ($port === null) { $status = 'port_range_exhausted'; return null; }
        if (!pmssPortManagerWriteAssignedPort($context['dir'], $context['file'], $port)) { $status = 'write_failed'; return null; }

        $status = 'assigned';
        return $port;
    } finally {
        if ($lockHandle !== false) {
            pmssLockHandleRelease($lockHandle);
        }
    }
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
    if (!pmssPortManagerServiceNameIsValid($service)) {
        return pmssPortManagerFail("Error: invalid service\n");
    }

    if ($action === 'assign') {
        $assignStatus = '';
        $port = pmssPortManagerAssignServicePort($user, $service, null, $assignStatus);
        $assignStatus = $assignStatus !== '' ? $assignStatus : 'write_failed';
        if ($port !== null) {
            echo $port;
            pmssPortManagerLog($user, $action, $service, $port, $assignStatus === 'already_assigned' ? 'SKIP' : 'OK', $assignStatus);
            return 0;
        }
        if ($assignStatus === 'port_dir_unavailable') return pmssPortManagerFail("Error: unable to initialize port directory\n");
        if ($assignStatus === 'port_range_exhausted') return pmssPortManagerFail("Error: no free port available\n", $user, $action, $service, null, 'ERR', 'port_range_exhausted');
        if ($assignStatus === 'unsafe_assignment_path') return pmssPortManagerFail("Error: invalid port assignment path\n", $user, $action, $service, null, 'ERR', 'unsafe_assignment_path');
        if ($assignStatus === 'write_failed') return pmssPortManagerFail("Error: failed to persist port assignment\n", $user, $action, $service, null, 'ERR', 'write_failed');
        return pmssPortManagerFail("Error: invalid stored port assignment\n", $user, $action, $service, null, 'ERR', $assignStatus === 'invalid_existing_assignment' ? 'invalid_existing_assignment' : 'unsafe_assignment_path');
    }

    $context = pmssPortManagerAssignmentContext($user, $service, $contextStatus);
    if ($context === null) {
        if ($contextStatus === 'port_dir_unavailable') return pmssPortManagerFail("Error: unable to initialize port directory\n");
        return pmssPortManagerFail("Error: invalid stored port assignment\n", $user, $action, $service, null, 'ERR', 'unsafe_assignment_path');
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
        if (!pmssPortManagerAssignmentPathIsSafe($context['dir'], $context['file'])) return pmssPortManagerFail("Error: invalid stored port assignment\n", $user, $action, $service, null, 'ERR', 'unsafe_assignment_path');
        if (!@unlink($context['file'])) return pmssPortManagerFail("Error: failed to release port\n", $user, $action, $service, null, 'ERR', 'release_failed');
        echo 'Port released';
        pmssPortManagerLog($user, $action, $service, null, 'OK', 'released');
        return 0;
    } finally {
        if ($lockHandle !== false) pmssLockHandleRelease($lockHandle);
    }
}
