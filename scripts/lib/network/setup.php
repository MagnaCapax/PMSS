<?php
/** Network setup discovery and ordered firewall policy. @license GPL-3.0-only */

/** Resolve the uplink and shaping speed without changing installed network policy. */
function networkSetupUplinkRead(array $networkConfig): array
{
    // Resolve the uplink interface and current speed capacity.
    $configuredInterface = trim((string) ($networkConfig['interface'] ?? ''));
    $interface = $configuredInterface === '' ? detectPrimaryInterface() : networkInterfaceNameNormalized($configuredInterface);
    if ($configuredInterface !== '' && $interface === '') {
        logMessage('setupNetwork: unsafe configured interface name');
        die("Error: Unsafe configured network interface\n");
    }

    // If no explicit interface is configured, avoid tunnel defaults (tun/wg/tap).
    if ($configuredInterface === '' && preg_match('/^(tun|tap|wg)/', $interface)) {
        $routes = trim((string) shell_exec('/sbin/ip route show default 2>/dev/null'));
        $fallback = '';
        if ($routes !== '') {
            foreach (explode("\n", $routes) as $line) {
                if (preg_match('/\\bdev\\s+(\\S+)/', $line, $matches)) {
                    $candidate = networkInterfaceNameNormalized($matches[1]);
                    if ($candidate !== '' && !preg_match('/^(tun|tap|wg)/', $candidate)) {
                        $fallback = $candidate;
                        break;
                    }
                }
            }
        }
        if ($fallback !== '' && $fallback !== $interface) {
            logMessage("setupNetwork: detected tunnel uplink {$interface}; using {$fallback} instead");
            $interface = $fallback;
        }
    }

    if ($interface === '') {
        die("Error: Could not determine primary interface\n");
    }

    $linkSpeed = getLinkSpeed($interface);
    $detectedLinkSpeed = getDetectedLinkSpeed($interface);
    $configuredSpeed = isset($networkConfig['speed']) ? (int) $networkConfig['speed'] : 0;
    if ($configuredSpeed > 0 && $detectedLinkSpeed > $configuredSpeed) {
        logMessage(sprintf(
            'setupNetwork: detected %s link speed %dMbit exceeds configured FireQOS speed %dMbit; keeping configured cap',
            $interface,
            $detectedLinkSpeed,
            $configuredSpeed
        ));
    }

    return [$interface, $linkSpeed];
}

/** Build the legacy filter/NAT pair; rendering validates every rule before application. */
function networkSetupFirewallCommands(array $monitoringCommands): array
{
    // Keep low-MSS protection first, bogon drops next, and accounting last.
    return ['filter' => array_merge([
        '-A INPUT -p tcp --tcp-flags SYN SYN -m tcpmss --mss 1:500 -m limit --limit 2/second --limit-burst 10 -j LOG --log-prefix "tcpsack: " --log-level 4',
        '-A INPUT -p tcp --tcp-flags SYN SYN -m tcpmss --mss 1:500 -j DROP',
    ], array_map(static function (string $source): string {
        return "-A INPUT -i ##IFACE## -s {$source} -j DROP";
    }, [
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
    ]), [
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
