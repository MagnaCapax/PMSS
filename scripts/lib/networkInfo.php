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
require_once __DIR__.'/network/interface.php';

/** Normalize interface names before they reach shell commands. */
function networkInterfaceNameNormalized(string $iface): string
{
    return pmssNetworkInterfaceNameNormalize($iface);
}

/** Determine the primary network interface name. */
function detectPrimaryInterface(): string
{
    $config = networkLoadConfig();
    if (is_array($config) && !empty($config['interface'])) {
        $iface = networkInterfaceNameNormalized((string) $config['interface']);
        if ($iface !== '') {
            return $iface;
        }
    }

    $iface = networkInterfaceNameNormalized(trim((string) shell_exec("/sbin/ip route | awk '/default/ {print \$5; exit}'")));
    return $iface !== '' ? $iface : 'eth0';
}

/** Probe the physical interface speed from sysfs, returning 0 when unknown. */
function pmssNetworkProbeSysfsLinkSpeed(string $iface): int
{
    $iface = networkInterfaceNameNormalized($iface);
    if ($iface === '') {
        return 0;
    }

    $baseDir = getenv('PMSS_NETWORK_SYS_CLASS_NET_DIR');
    if (!is_string($baseDir) || trim($baseDir) === '' || strpos($baseDir, "\0") !== false) {
        $baseDir = '/sys/class/net';
    }

    $raw = @file_get_contents(rtrim($baseDir, '/').'/'.$iface.'/speed');
    $speed = is_string($raw) ? trim($raw) : '';
    if ($speed === '' || ctype_digit($speed) === false) {
        return 0;
    }

    $speedMbit = (int) $speed;
    return $speedMbit > 0 ? $speedMbit : 0;
}

/** Probe the physical interface speed with ethtool, returning 0 when unknown. */
function pmssNetworkProbeEthtoolLinkSpeed(string $iface): int
{
    $iface = networkInterfaceNameNormalized($iface);
    if ($iface === '') {
        return 0;
    }

    $raw = shell_exec('/sbin/ethtool '.escapeshellarg($iface).' 2>/dev/null');
    return $raw && preg_match('/Speed:\s*(\d+)Mb/', $raw, $m) ? (int) $m[1] : 0;
}

/** Detect physical interface speed in Mbps without applying config overrides. */
function getDetectedLinkSpeed(string $iface): int
{
    $speed = pmssNetworkProbeSysfsLinkSpeed($iface);
    return $speed > 0 ? $speed : pmssNetworkProbeEthtoolLinkSpeed($iface);
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
