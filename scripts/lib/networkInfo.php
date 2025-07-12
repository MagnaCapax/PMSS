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
 */

/** Determine the primary network interface name. */
function detectPrimaryInterface(): string
{
    if (file_exists('/etc/seedbox/config/network')) {
        $cfg = include '/etc/seedbox/config/network';
        if (is_array($cfg) && !empty($cfg['interface'])) {
            return $cfg['interface'];
        }
    }

    $iface = trim(shell_exec("/sbin/ip route | awk '/default/ {print \$5; exit}'"));
    if ($iface === '') {
        $iface = 'eth0';
    }
    return $iface;
}

/** Detect interface speed in Mbps using configuration or ethtool. */
function getLinkSpeed(string $iface): int
{
    if (file_exists('/etc/seedbox/config/network')) {
        $cfg = include '/etc/seedbox/config/network';
        if (is_array($cfg) && isset($cfg['speed'])) {
            return (int)$cfg['speed'];
        }
    }

    $raw = shell_exec("/sbin/ethtool {$iface} 2>/dev/null | grep 'Speed:'");
    if ($raw && preg_match('/Speed:\s*(\d+)Mb/', $raw, $m)) {
        return (int)$m[1];
    }

    return 1000; // default if detection fails
}

$link = detectPrimaryInterface();
$linkSpeed = getLinkSpeed($link);

