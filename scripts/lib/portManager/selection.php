<?php
/**
 * Service-port allocator internals. Loaded by portManager.php.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Confirm that a candidate can bind on loopback and every IPv4 interface. */
function pmssPortManagerPortIsAvailable(int $port): bool
{
    if (!pmssNetworkPortInRange($port, PMSS_PORT_MANAGER_MIN_PORT, PMSS_PORT_MANAGER_MAX_PORT)) {
        return false;
    }

    foreach (array('127.0.0.1', '0.0.0.0') as $address) {
        $errno = 0;
        $error = '';
        $server = @stream_socket_server(
            sprintf('tcp://%s:%d', $address, $port),
            $errno,
            $error,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );
        if ($server === false) {
            return false;
        }
        fclose($server);
    }

    return true;
}

/**
 * Pick one free service port without risking an unbounded collision loop.
 *
 * @param array<int, bool> $used
 */
function pmssPortManagerSelectAvailablePort(array $used): ?int
{
    // A random order over the full range is also random over its unused subset.
    $ports = range(PMSS_PORT_MANAGER_MIN_PORT, PMSS_PORT_MANAGER_MAX_PORT);
    shuffle($ports);
    foreach ($ports as $port) {
        if (!isset($used[$port]) && pmssPortManagerPortIsAvailable($port)) return $port;
    }

    return null;
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
    if ($lockHandle === false) {
        $status = 'lock_failed';
        return null;
    }
    try {
        // The assignment may have changed while waiting for the shared lock.
        $context = pmssPortManagerAssignmentContext($user, $service, $status);
        if ($context === null) return null;
        if ($context['present']) {
            $port = pmssPortManagerReadAssignedPort($context['file']);
            if ($port === null) { $status = 'invalid_existing_assignment'; return null; }
            $status = 'already_assigned';
            return $port;
        }

        $legacyDir = rtrim(pmssResolvePathFromEnv('PMSS_PORT_MANAGER_LEGACY_DIR', pmssPortManagerDefaultPath('legacy-rtorrent-ports', '/var/lib/pmss/ports')), '/');
        $used = pmssPortManagerUsedPorts($context['dir'], $legacyDir);
        $port = ($preferredPort !== null
            && pmssNetworkPortInRange($preferredPort, PMSS_PORT_MANAGER_MIN_PORT, PMSS_PORT_MANAGER_MAX_PORT)
            && !isset($used[$preferredPort])
            && pmssPortManagerPortIsAvailable($preferredPort))
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
