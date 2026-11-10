<?php
/**
 * Utilities for retrieving network interface details.
 *
 * Including this file defines `$link` and `$linkSpeed` variables for the
 * primary interface and its configured speed in Mbps. Configuration values
 * from the seedbox network config remain authoritative for shaping caps, while
 * the physical link probe can be used to surface drift.
 *
 * Verified to work on Debian 10, 11 and 12. Older releases like Debian 8
 * should also function provided `iproute2` and `ethtool` are available.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/network/config.php';
require_once __DIR__.'/network/linkSpeed.php';

/** Determine the primary network interface name. */
function detectPrimaryInterface(): string
{
    $config = networkLoadConfig();
    if (is_array($config) && !empty($config['interface'])) {
        $iface = pmssNetworkInterfaceNameNormalize((string) $config['interface']);
        if ($iface !== '') {
            return $iface;
        }
    }

    $iface = pmssNetworkInterfaceNameNormalize((string) shell_exec("/sbin/ip route | awk '/default/ {print \$5; exit}'"));
    return $iface !== '' ? $iface : 'eth0';
}

/** Resolve configured link speed in Mbps, falling back to physical detection. */
function getLinkSpeed(string $iface): int
{
    $config = networkLoadConfig();
    if (is_array($config) && isset($config['speed'])) {
        return (int) $config['speed'];
    }

    $speed = getDetectedLinkSpeed($iface);
    return $speed > 0 ? $speed : 1000;
}

$link = detectPrimaryInterface();
$linkSpeed = getLinkSpeed($link);
