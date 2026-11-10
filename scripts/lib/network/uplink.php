<?php
/** Setup uplink selection and shaping-speed drift diagnostics. @license GPL-3.0-only */

require_once __DIR__.'/interface.php';

/** Resolve the uplink and shaping speed without changing installed network policy. */
function networkSetupUplinkRead(array $networkConfig): array
{
    // Resolve the uplink interface and current speed capacity.
    $configuredInterface = trim((string) ($networkConfig['interface'] ?? ''));
    $interface = $configuredInterface === '' ? detectPrimaryInterface() : pmssNetworkInterfaceNameNormalize($configuredInterface);
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
                    $candidate = pmssNetworkInterfaceNameNormalize($matches[1]);
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
