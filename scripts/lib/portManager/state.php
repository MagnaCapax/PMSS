<?php
/**
 * Service-port allocator internals. Loaded by portManager.php.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Keep test-mode allocator state under the shared hermetic test root. */
function pmssPortManagerDefaultPath(string $leaf, string $productionPath): string
{
    if (getenv('PMSS_TEST_MODE') !== '1') {
        return $productionPath;
    }
    $testRoot = getenv('PMSS_TEST_TEMP_ROOT');
    return (is_string($testRoot) && $testRoot !== '' ? rtrim($testRoot, '/') : sys_get_temp_dir()).'/'.$leaf;
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

/** Guard assignment file reads/writes/removals against symlink and type tricks. */
function pmssPortManagerAssignmentPathIsSafe(string $portDir, string $portFile): bool
{
    $portDir = rtrim($portDir, '/');
    return $portDir !== '' && dirname($portFile) === $portDir
        && is_dir($portDir) && !is_link($portDir) && pmssLockFilePathIsSafe($portFile);
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
    $present = pmssPathExistsOrLink($portFile);
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

/** The legacy and managed reservation scans use the same root boundary. */
function pmssPortManagerReservationRootIsSafe(string $root): bool
{
    return $root !== '' && strpos($root, "\0") === false && is_dir($root) && !is_link($root);
}

/** @return array<int, bool> */
function pmssPortManagerLegacyUsedPorts(string $portsBase = '/var/lib/pmss/ports'): array
{
    $portsBase = rtrim($portsBase, '/');
    if (!pmssPortManagerReservationRootIsSafe($portsBase)) {
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
    if (!pmssPortManagerReservationRootIsSafe($portDir)) {
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
