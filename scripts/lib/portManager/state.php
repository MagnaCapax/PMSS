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

/** Resolve the shared service-port reservation path without creating it. */
function pmssPortManagerReservationPath(): string
{
    return rtrim(pmssResolvePathFromEnv('PMSS_PORT_MANAGER_DIR', pmssPortManagerDefaultPath('port-manager', '/etc/seedbox/runtime/ports')), '/');
}

/** Resolve and initialize the shared service-port reservation directory. */
function pmssPortManagerReservationDir(): ?string
{
    $portDir = pmssPortManagerReservationPath();
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

/** Scan, recheck the root, then decode reservations; missing legacy roots are valid. */
function pmssPortManagerReservationPorts(string $root, string $pattern, bool $allowMissing, callable $readPort, ?bool &$scanComplete): array
{
    $scanComplete = false;
    if (!pmssPortManagerReservationRootIsSafe($root)) {
        $scanComplete = $allowMissing && !pmssPathExistsOrLink($root);
        return [];
    }
    $paths = @glob($root.$pattern);
    $scanComplete = $paths !== false && pmssPortManagerReservationRootIsSafe($root);
    $used = array();
    foreach ($scanComplete ? $paths : [] as $path) {
        if (($port = $readPort($path)) !== null) $used[$port] = true;
    }
    return $used;
}

/** @return array<int, bool> */
function pmssPortManagerLegacyUsedPorts(string $portsBase = '/var/lib/pmss/ports', ?bool &$scanComplete = null): array
{
    return pmssPortManagerReservationPorts(rtrim($portsBase, '/'), '/*/*', true,
        static function (string $path): ?int { return pmssNetworkPortParseDigits(basename($path)); }, $scanComplete);
}

/**
 * Return all ports already reserved in the shared service-port namespace.
 *
 * @return array<int, bool>
 */
function pmssPortManagerUsedPorts(string $portDir, string $legacyPortsBase = '/var/lib/pmss/ports', ?bool &$scanComplete = null): array
{
    $used = pmssPortManagerLegacyUsedPorts($legacyPortsBase, $legacyComplete);
    $managed = pmssPortManagerReservationPorts(rtrim($portDir, '/'), '/*', false,
        static function (string $path): ?int { return pmssPortManagerReadAssignedPort($path); }, $managedComplete);
    $scanComplete = $legacyComplete && $managedComplete;
    return $used + $managed;
}
