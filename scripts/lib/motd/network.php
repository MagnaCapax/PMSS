<?php
/** MOTD network-speed probe; route-get and sysfs retain precedence over fallbacks. */

require_once __DIR__.'/../runtime.php';

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
    return $net;
}
