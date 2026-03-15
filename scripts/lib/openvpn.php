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

function pmssOpenvpnSlug(): string
{
    return pmssOpenvpnSlugFromHostname(trim((string) @file_get_contents('/etc/hostname')));
}

function pmssOpenvpnArtifactPathsFromSlug(string $slug): array
{
    return $slug === ''
        ? ['', '']
        : ['/home/openvpn-'.$slug.'.ovpn', '/home/openvpn-'.$slug.'.crt'];
}

function pmssOpenvpnArtifactPaths(): array
{
    return pmssOpenvpnArtifactPathsFromSlug(pmssOpenvpnSlug());
}

/**
 * Return true if OpenVPN is configured (binary + server conf + CA + client artifacts).
 */
function pmssOpenvpnIsConfigured(): bool
{
    $bin = trim((string) @shell_exec('command -v openvpn 2>/dev/null'));
    if ($bin === '') {
        return false;
    }
    $easyRsaDir = '/etc/openvpn/easy-rsa';
    list($ovpn, $crt) = pmssOpenvpnArtifactPaths();
    return is_file('/etc/openvpn/openvpn.conf')
        && (is_file($easyRsaDir.'/pki/ca.crt') || is_file($easyRsaDir.'/pki/issued/server.crt'))
        && $ovpn !== '' && $crt !== '' && is_file($ovpn) && is_file($crt);
}
