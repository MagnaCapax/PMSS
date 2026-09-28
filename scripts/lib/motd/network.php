<?php
/** MOTD network-speed probe; route-get and sysfs retain precedence over fallbacks. */

require_once __DIR__.'/../runtime.php';
require_once __DIR__.'/../network/config.php';

/** Return the detected speed string, or Unknown when neither probe succeeds. */
function pmssMotdNetworkSpeed(): string
{
    $iface = '';
    // Discover the primary interface via routing table, preferring route-get.
    foreach (['ip -o route get 1 2>/dev/null', 'ip route show default 2>/dev/null'] as $routeCommand) {
        $route = pmssConfigLineColumns((string) shell_exec($routeCommand), 0, []);
        $ifaceIndex = array_search('dev', $route, true);
        $iface = ($ifaceIndex !== false && isset($route[$ifaceIndex + 1])) ? $route[$ifaceIndex + 1] : '';
        if ($iface !== '') {
            break;
        }
    }
    $net = 'Unknown';
    if ($iface !== '') {
        // Prefer sysfs when available
        $sysSpeed = "/sys/class/net/".$iface."/speed";
        if (@is_file($sysSpeed)) {
            $val = trim((string) @file_get_contents($sysSpeed));
            if ($val !== '' && ctype_digit(str_replace(['-','+'], '', $val))) {
                $intVal = (int) $val;
                if ($intVal > 0) {
                    $net = $intVal.'Mb/s';
                }
            }
        }
        // Fallback to ethtool on detected interface
        if ($net === 'Unknown') {
            $nsRaw = shell_exec('ethtool '.escapeshellarg($iface)." 2>/dev/null | grep 'Speed:'");
            if ($nsRaw && preg_match('/Speed:\\s+(\\S+)/', $nsRaw, $m)) {
                $net = $m[1];
            }
        }
    }

    // Most of the fleet is virtio-backed, where the NIC reports no link speed at
    // all: sysfs gives -1 and ethtool prints a literal "Unknown!". The banner then
    // said Unknown on a correctly provisioned box. The configured egress rate is the
    // honest answer there -- it is the same value FireQOS shapes egress to
    // (scripts/lib/network/fireqos.php:64).
    if (!pmssMotdNetworkSpeedIsResolved($net)) {
        $configured = pmssMotdConfiguredLinkSpeedLabel();
        if ($configured !== '') {
            $net = $configured;
        }
    }

    return $net;
}

/**
 * A probe result counts as resolved only when it starts with a digit.
 *
 * ethtool prints "Speed: Unknown!" on a virtio NIC and the existing regex captures
 * that verbatim, so comparing against the string 'Unknown' alone misses the case
 * this fallback exists for.
 */
function pmssMotdNetworkSpeedIsResolved(string $speed): bool
{
    return preg_match('/^\d/', trim($speed)) === 1;
}

/** Render a configured Mbit/s egress rate the way the banner should show it. */
function pmssMotdLinkSpeedLabel(int $mbit): string
{
    if ($mbit <= 0) {
        return '';
    }
    if ($mbit < 1000) {
        return $mbit.'Mb/s';
    }
    // 20000 -> 20Gb/s, 2500 -> 2.5Gb/s, and no trailing .0 on round values.
    return rtrim(rtrim(number_format($mbit / 1000, 1, '.', ''), '0'), '.').'Gb/s';
}

/** Provisioned egress speed from the network config, or '' when it is unset. */
function pmssMotdConfiguredLinkSpeedLabel(): string
{
    $config = networkLoadConfig();
    $speed = (isset($config['speed']) && is_numeric($config['speed'])) ? (int) $config['speed'] : 0;

    return pmssMotdLinkSpeedLabel($speed);
}
