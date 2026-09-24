<?php
/**
 * WireGuard endpoint discovery.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Confirm that the supplied address is a routable public IPv4 endpoint. */
function wgValidatePublicIp(string $candidate): ?string
{
    $ip = filter_var(trim($candidate), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    return $ip === false ? null : $ip;
}

/**
 * Query the external helper service for the host's public address.
 */
function wgExternalEndpointUrlCandidates(): array
{
    return [
        // Primary endpoint maintained by PMSS.
        'https://pulsedmedia.com/remote/myip.php',
        // Best-effort fallback used when the primary endpoint is unavailable.
        'https://api.ipify.org',
    ];
}

/**
 * Determine the best endpoint to advertise to tenants.
 */
function wgResolveEndpoint(string $hostname): array
{
    // Prefer DNS resolution before hitting external services or interface inspection.
    $hostnamePrivate = '';
    if ($hostname !== '') {
        $dnsOverride = getenv('PMSS_WG_DNS_IP');
        $resolved = $dnsOverride !== false && $dnsOverride !== '' ? $dnsOverride : gethostbyname($hostname);
        if ($resolved !== $hostname) {
            $hostnamePrivate = $resolved;
            $public     = wgValidatePublicIp($resolved);
            if ($public !== null) {
                return [$public, 'hostname'];
            }
        }
    }

    $interfacePrivate = '';
    $interfaceOverride = getenv('PMSS_WG_INTERFACE_IP');
    if ($interfaceOverride !== false) {
        $interfaceIp = trim($interfaceOverride);
        $interfaceIp = $interfaceIp === '' ? null : $interfaceIp;
    } else {
        $interface = detectPrimaryInterface();
        if ($interface === '') {
            $interfaceIp = null;
        } else {
            // Look for the primary IPv4 address associated with the uplink interface.
            $cmd = '/sbin/ip -4 -o addr show dev '.escapeshellarg($interface).' 2>/dev/null';
            exec($cmd, $output, $rc);
            $interfaceIp = null;
            if ($rc === 0) {
                foreach ($output as $line) {
                    if (preg_match('/inet\\s+([0-9.]+)/', $line, $matches)) {
                        $interfaceIp = $matches[1];
                        break;
                    }
                }
            }
        }
    }
    if ($interfaceIp !== null) {
        $public = wgValidatePublicIp($interfaceIp);
        if ($public !== null) {
            return [$public, 'interface'];
        }
        $interfacePrivate = $interfaceIp;
    }

    // #TODO Replace with an internal endpoint discovery helper instead of calling out. (GH #123)
    $externalOverride = getenv('PMSS_WG_EXTERNAL_IP');
    if ($externalOverride !== false) {
        $external = $externalOverride === '' ? null : wgValidatePublicIp($externalOverride);
    } else {
        $context = stream_context_create([
            'http' => [
                'timeout'    => 3,
                'user_agent' => 'PMSS WireGuard (+https://github.com/MagnaCapax/PMSS)',
            ],
        ]);

        $external = null;
        foreach (wgExternalEndpointUrlCandidates() as $url) {
            $response = @file_get_contents($url, false, $context);
            if ($response === false) {
                continue;
            }
            $ip = wgValidatePublicIp($response);
            if ($ip !== null) {
                $external = $ip;
                break;
            }
        }
    }
    if ($external !== null) {
        return [$external, 'external'];
    }

    if ($interfacePrivate !== '') {
        return [$interfacePrivate, 'interface_private'];
    }

    if ($hostnamePrivate !== '') {
        return [$hostnamePrivate, 'hostname_private'];
    }

    return ['', 'unknown'];
}

/** Prefer a resolvable hostname for client profiles, retaining IP fallback. */
function wgResolveClientEndpoint(string $hostname): array
{
    $hostname = trim($hostname);
    if (pmssHostnameIsValid($hostname, false)) {
        $dnsOverride = getenv('PMSS_WG_DNS_IP');
        $resolved = $dnsOverride !== false && trim($dnsOverride) !== ''
            ? trim($dnsOverride)
            : gethostbyname($hostname);
        if ($resolved !== $hostname && wgValidatePublicIp($resolved) !== null) {
            return [$hostname, 'hostname'];
        }
    }

    return wgResolveEndpoint($hostname);
}
