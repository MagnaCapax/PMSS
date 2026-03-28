<?php
/**
 * Utilities for retrieving network interface details.
 *
 * Including this file defines `$link` and `$linkSpeed` variables for the
 * primary interface and its speed in Mbps. Configuration values from the
 * seedbox network config are used when available and otherwise
 * detection falls back to `ip` and `ethtool`.
 *
 * Verified to work on Debian 10, 11 and 12. Older releases like Debian 8
 * should also function provided `iproute2` and `ethtool` are available.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/network/config.php';

/** Normalize interface names before they reach shell commands. */
function networkInterfaceNameNormalized(string $iface): string
{
    $iface = trim($iface);
    return $iface !== '' && preg_match('/^[A-Za-z0-9_.:-]+$/', $iface) === 1 ? $iface : '';
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

/** Detect interface speed in Mbps using configuration or ethtool. */
function getLinkSpeed(string $iface): int
{
    $config = networkLoadConfig();
    if (is_array($config) && isset($config['speed'])) {
        return (int) $config['speed'];
    }

    $iface = networkInterfaceNameNormalized($iface);
    if ($iface === '') {
        return 1000;
    }

    $raw = shell_exec('/sbin/ethtool '.escapeshellarg($iface).' 2>/dev/null');
    return $raw && preg_match('/Speed:\s*(\d+)Mb/', $raw, $m) ? (int) $m[1] : 1000;
}

$link = detectPrimaryInterface();
$linkSpeed = getLinkSpeed($link);
