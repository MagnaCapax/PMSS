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
    if ($hostname === '') {
        return '';
    }
    if (strpos($hostname, '.pulsedmedia.com') === false) {
        return $hostname.'.pulsedmedia.com';
    }
    return $hostname;
}

function pmssOpenvpnSlugFromHostname(string $hostname): string
{
    $fqdn = pmssOpenvpnFqdnFromHostname($hostname);
    return $fqdn === '' ? '' : str_replace('.', '-', $fqdn);
}

function pmssOpenvpnSlug(): string
{
    $hostname = @file_get_contents('/etc/hostname');
    $hostname = $hostname === false ? '' : trim($hostname);
    return pmssOpenvpnSlugFromHostname($hostname);
}

function pmssOpenvpnArtifactPathsFromSlug(string $slug): array
{
    $ovpn = $slug !== '' ? ('/home/openvpn-'.$slug.'.ovpn') : '';
    $crt  = $slug !== '' ? ('/home/openvpn-'.$slug.'.crt') : '';
    return [$ovpn, $crt];
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
    $serverConf = '/etc/openvpn/openvpn.conf';
    $easyRsaDir = '/etc/openvpn/easy-rsa';
    $hasServer  = is_file($serverConf);
    $hasCa      = is_file($easyRsaDir.'/pki/ca.crt') || is_file($easyRsaDir.'/pki/issued/server.crt');
    list($ovpn, $crt) = pmssOpenvpnArtifactPaths();
    $hasClient = ($ovpn !== '' && is_file($ovpn)) && ($crt !== '' && is_file($crt));
    return $hasServer && $hasCa && $hasClient;
}
