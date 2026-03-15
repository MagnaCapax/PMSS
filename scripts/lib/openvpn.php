<?php
/**
 * Shared OpenVPN helpers (slug/artifacts/configuration checks).
 *
 * PHP 7.3 compatible helpers used by systemTest and configureOpenvpn.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

function pmssOpenvpnFqdnFromHostname(string $hostname): string
{
    $hostname = trim($hostname);
    return ($hostname === '' || strpos($hostname, '.pulsedmedia.com') !== false)
        ? $hostname
        : $hostname.'.pulsedmedia.com';
}

function pmssOpenvpnSlugFromHostname(string $hostname): string
{
    $fqdn = pmssOpenvpnFqdnFromHostname($hostname);
    return $fqdn === '' ? '' : str_replace('.', '-', $fqdn);
}

function pmssOpenvpnArtifactPathsFromSlug(string $slug): array
{
    return $slug === ''
        ? ['', '']
        : ['/home/openvpn-'.$slug.'.ovpn', '/home/openvpn-'.$slug.'.crt'];
}
