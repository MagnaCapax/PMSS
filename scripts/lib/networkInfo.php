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

/** Determine the primary network interface name. */
function detectPrimaryInterface(): string
{
    $configPath = '/etc/seedbox/config/network';
    $config = file_exists($configPath) ? include $configPath : null;
    if (is_array($config) && !empty($config['interface'])) {
        return (string) $config['interface'];
    }

    $iface = trim((string) shell_exec("/sbin/ip route | awk '/default/ {print \$5; exit}'"));
    return $iface !== '' ? $iface : 'eth0';
}

/** Detect interface speed in Mbps using configuration or ethtool. */
function getLinkSpeed(string $iface): int
{
    $configPath = '/etc/seedbox/config/network';
    $config = file_exists($configPath) ? include $configPath : null;
    if (is_array($config) && isset($config['speed'])) {
        return (int) $config['speed'];
    }

    if ($iface === '') {
        return 1000;
    }

    $raw = shell_exec("/sbin/ethtool {$iface} 2>/dev/null | grep 'Speed:'");
    return $raw && preg_match('/Speed:\s*(\d+)Mb/', $raw, $m) ? (int) $m[1] : 1000;
}

$link = detectPrimaryInterface();
$linkSpeed = getLinkSpeed($link);
