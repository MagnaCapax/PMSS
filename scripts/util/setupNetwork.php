#!/usr/bin/env php
<?php
/**
 * PMSS network bootstrap utility.
 *
 * Centralises firewall and FireQOS updates so update.php can invoke it
 * repeatedly while keeping tenant-specific overrides intact.
 *
 * @author    Aleksi Ursin <123067457+MagnaCapax@users.noreply.github.com>
 * @copyright 2010-2025 Magna Capax Finland Oy
 *
 * @license GPL-3.0-only
 */

require_once '/scripts/lib/network/config.php';
require_once '/scripts/lib/network/setup.php';
require_once '/scripts/lib/networkInfo.php';
require_once '/scripts/lib/network/iptables.php';
require_once '/scripts/lib/network/fireqos.php';
require_once '/scripts/lib/userLifecycle.php';
// Collect tenant usernames for FireQOS shaping.
$users = pmssListManagedUsers('/scripts/listUsers.php');

// Retrieve persisted interface selections and LAN bypass ranges.
$networkConfig = networkLoadConfig();
$localnets     = networkLoadLocalnets();

[$interface, $linkSpeed] = networkSetupUplinkRead($networkConfig);

// Pull optional monitoring chain additions from helper script output.
$monitoringCommands = [];
if (networkIptablesOwnerMatchAvailable()) {
    $monitoringCommands = networkLoadMonitoringCommands();
} else {
    logMessage('iptables owner match unavailable; skipping per-user monitoring rules');
}

// Render the same uplink into both legacy tokens.
$replacements = [
    '##IFACE##' => $interface,
    '##LINK##'  => $interface,
];

$commands = networkSetupFirewallCommands($monitoringCommands);

// Ensure kernel forwarding remains enabled for tenant networks.
if (@file_put_contents('/proc/sys/net/ipv4/ip_forward', '1') === false) {
    logMessage('setupNetwork: unable to enable IPv4 forwarding');
}

// Prefer atomic iptables-restore to minimise transient inconsistencies.
$renderedIptables = networkIptablesRenderCommandSets($commands['filter'], $commands['nat'], $replacements);
if ($renderedIptables !== null && !networkApplyIptablesAtomically($renderedIptables['filter'], $renderedIptables['nat'])) {
    logMessage('iptables-restore failed, falling back to sequential rules');
    networkApplyIptablesFallback($commands['filter'], $commands['nat'], $replacements);
}

// Render FireQOS shaping rules with the refreshed tenant list.
$fireqosNetworkConfig = $networkConfig;
$fireqosNetworkConfig['interface'] = $interface;
if (!array_key_exists('speed', $fireqosNetworkConfig)) {
    $fireqosNetworkConfig['speed'] = $linkSpeed;
}
$fireqosConfig = networkBuildFireqosConfig(
    $fireqosNetworkConfig,
    $users,
    $localnets
);
// Load the generated policy into the running FireQOS instance; callers log a non-zero exit.
exit(networkApplyFireqos($fireqosConfig) ? 0 : 1);
