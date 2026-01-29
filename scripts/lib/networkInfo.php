<?php
/**
 * Utilities for retrieving network interface details.
 *
 * Including this file defines `$link` and `$linkSpeed` variables for the
 * primary interface and its speed in Mbps. Configuration values from
 * `/etc/seedbox/config/network` are used when available and otherwise
 * detection falls back to `ip` and `ethtool`.
 *
 * Verified to work on Debian 10, 11 and 12. Older releases like Debian 8
 * should also function provided `iproute2` and `ethtool` are available.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Load the persisted network configuration when available.
 *
 * @return array|null Returns the config array or null when not present/invalid.
 */
function pmssLoadSeedboxNetworkConfig(): ?array
{
    if (!file_exists('/etc/seedbox/config/network')) {
        return null;
    }

    $cfg = include '/etc/seedbox/config/network';
    return is_array($cfg) ? $cfg : null;
}

/** Determine the primary network interface name. */
function detectPrimaryInterface(): string
{
    $cfg = pmssLoadSeedboxNetworkConfig();
    if ($cfg !== null && !empty($cfg['interface'])) {
        return (string) $cfg['interface'];
    }

    $iface = trim((string) shell_exec("/sbin/ip route | awk '/default/ {print \$5; exit}'"));
    return $iface !== '' ? $iface : 'eth0';
}

/** Detect interface speed in Mbps using configuration or ethtool. */
function getLinkSpeed(string $iface): int
{
    $cfg = pmssLoadSeedboxNetworkConfig();
    if ($cfg !== null && isset($cfg['speed'])) {
        return (int) $cfg['speed'];
    }

    if ($iface === '') {
        return 1000;
    }

    $raw = shell_exec("/sbin/ethtool {$iface} 2>/dev/null | grep 'Speed:'");
    if ($raw && preg_match('/Speed:\s*(\d+)Mb/', $raw, $m)) {
        return (int)$m[1];
    }

    return 1000; // default if detection fails
}

$link = detectPrimaryInterface();
$linkSpeed = getLinkSpeed($link);
