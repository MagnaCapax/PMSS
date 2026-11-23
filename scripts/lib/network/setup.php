<?php
/** Network setup discovery and ordered firewall policy. @license GPL-3.0-only */

require_once __DIR__.'/uplink.php';

/**
 * Private and shared address space dropped on the uplink by default (RFC 6598, RFC 1918 minus 10/8).
 * A NAT or home-LAN install receives its own admin traffic from these ranges, so the
 * `dropPrivateSources` network setting can turn exactly these drops off.
 */
const NETWORK_SETUP_PRIVATE_SOURCE_RANGES = ['100.64.0.0/10', '172.16.0.0/12', '192.168.0.0/16'];

/** True unless the network config sets `dropPrivateSources` to boolean false (absent or any other value keeps the drops). */
function networkSetupDropsPrivateSources(array $networkConfig): bool
{
    return ($networkConfig['dropPrivateSources'] ?? true) !== false;
}

/**
 * Build the legacy filter/NAT pair; rendering validates every rule before application.
 * With $dropPrivateSources false only the private/shared-range source drops are omitted.
 */
function networkSetupFirewallCommands(array $monitoringCommands, bool $dropPrivateSources = true): array
{
    $bogonSources = [
        '0.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/3',
    ];
    if (!$dropPrivateSources) {
        $bogonSources = array_values(array_diff($bogonSources, NETWORK_SETUP_PRIVATE_SOURCE_RANGES));
    }

    // Keep low-MSS protection first, bogon drops next, and accounting last.
    return ['filter' => array_merge([
        '-A INPUT -p tcp --tcp-flags SYN SYN -m tcpmss --mss 1:500 -m limit --limit 2/second --limit-burst 10 -j LOG --log-prefix "tcpsack: " --log-level 4',
        '-A INPUT -p tcp --tcp-flags SYN SYN -m tcpmss --mss 1:500 -j DROP',
    ], array_map(static function (string $source): string {
        return "-A INPUT -i ##IFACE## -s {$source} -j DROP";
    }, $bogonSources), [
        '-A INPUT -i ##IFACE## -m state --state NEW -p udp --dport 1194 -j ACCEPT',
        '-A INPUT -i ##IFACE## -m state --state NEW -p udp --dport 51820 -j ACCEPT',
        // Defense-in-depth: block common default Web UI ports for distro-provided torrent services.
        // PMSS runs these apps per-user on random high ports and proxies via nginx/lighttpd.
        '-A INPUT -i ##IFACE## -p tcp --dport 8080 -j DROP',
        '-A INPUT -i ##IFACE## -p tcp --dport 8112 -j DROP',
        '-A INPUT -i tun+ -j ACCEPT',
        '-A INPUT -i wg+ -j ACCEPT',
        // Block peer-to-peer overlay forwarding before permitting overlay egress.
        '-A FORWARD -i tun+ -o tun+ -j DROP',
        '-A FORWARD -i tun+ -j ACCEPT',
        '-A FORWARD -i tun+ -o ##IFACE## -m state --state RELATED,ESTABLISHED -j ACCEPT',
        '-A FORWARD -i ##IFACE## -o tun+ -m state --state RELATED,ESTABLISHED -j ACCEPT',
        '-A FORWARD -i wg0 -o wg0 -j DROP',
        '-A FORWARD -i wg+ -o ##IFACE## -j ACCEPT',
        '-A FORWARD -i ##IFACE## -o wg+ -m state --state RELATED,ESTABLISHED -j ACCEPT',
        '-A OUTPUT -o tun+ -j ACCEPT',
        '-A OUTPUT -o wg+ -j ACCEPT',
    ], $monitoringCommands), 'nat' => [
        '-A POSTROUTING -s 10.8.0.0/24 -o ##LINK## -j MASQUERADE',
        '-A POSTROUTING -s 10.90.90.0/24 -o ##LINK## -j MASQUERADE',
    ]];
}
