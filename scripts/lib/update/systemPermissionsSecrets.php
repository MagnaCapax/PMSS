<?php
/** TLS, OpenVPN and WireGuard permission policy after broad tree normalization.
 * @license GPL-3.0-only
 */
require_once __DIR__.'/systemPermissionsTargets.php';

/** Tighten the existing service-secret targets without reading secret contents. */
function pmssSystemPermissionsSecretsApply(array &$exitCodes): void
{
    // Keep scope narrow, command bytes stable and directory checks ahead of each group.
    $letsencryptSteps = [
        '/etc/letsencrypt/live' => [
            ['Hardening TLS private keys (Let\'s Encrypt)', "find /etc/letsencrypt/live -type f -name 'privkey.pem' -not -perm 0600 -exec chmod 600 {} +"],
        ],
        '/etc/letsencrypt/accounts' => [
            ['Hardening TLS account keys (Let\'s Encrypt)', "find /etc/letsencrypt/accounts -type f \\( -name 'private_key.json' -o -name 'private_key*.pem' \\) -not -perm 0600 -exec chmod 600 {} +"],
            ['Hardening TLS account metadata (Let\'s Encrypt)', "find /etc/letsencrypt/accounts -type f \\( -name 'registration.json' -o -name 'meta.json' \\) -not -perm 0600 -exec chmod 600 {} +"],
        ],
        '/etc/letsencrypt/archive' => [
            ['Hardening TLS archive keys (Let\'s Encrypt)', "find /etc/letsencrypt/archive -type f -name 'privkey*.pem' -not -perm 0600 -exec chmod 600 {} +"],
        ],
        '/etc/letsencrypt/renewal' => [
            ['Restricting TLS renewal configs (Let\'s Encrypt)', "find /etc/letsencrypt/renewal -type f -name '*.conf' -not -perm 0600 -exec chmod 600 {} +"],
        ],
    ];
    foreach ($letsencryptSteps as $path => $steps) pmssPermissionStepsRun($path, $steps, $exitCodes);
    foreach (['/var/lib/letsencrypt', '/var/log/letsencrypt'] as $path) {
        pmssPermissionStepsRun($path, [['Restricting '.$path, 'chmod 700 '.$path]], $exitCodes, true);
    }
    pmssPermissionStepsRun('/etc/seedbox/config/ssl', [
        ['Hardening seedbox SSL private keys', "find /etc/seedbox/config/ssl -type f -name 'privkey.pem' -not -perm 0600 -exec chmod 600 {} +"],
    ], $exitCodes);
    pmssPermissionStepsRun('/etc/openvpn/easy-rsa/pki/private', [
        ['Restricting OpenVPN private key directory', 'chmod 700 /etc/openvpn/easy-rsa/pki/private'],
        ['Hardening OpenVPN private keys', "find /etc/openvpn/easy-rsa/pki/private -type f -name '*.key' -not -perm 0600 -exec chmod 600 {} +"],
    ], $exitCodes, true);
    foreach (['/etc/openvpn/easy-rsa/pki/issued', '/etc/openvpn/easy-rsa/pki/reqs', '/etc/openvpn/easy-rsa/pki/crl', '/etc/openvpn/easy-rsa/pki/certs_by_serial'] as $dir) {
        pmssPermissionStepsRun($dir, [['Restricting OpenVPN PKI directory '.$dir, 'chmod 750 '.escapeshellarg($dir)]], $exitCodes, true);
    }
    foreach (['/etc/openvpn/easy-rsa/pki/crl.pem' => 'OpenVPN CRL', '/etc/openvpn/ta.key' => 'OpenVPN ta.key'] as $path => $label) {
        pmssPermissionStepsRun($path, [['Restricting '.$label, 'chmod 600 '.$path]], $exitCodes, true, false);
    }
    // OpenVPN config ownership deliberately follows its mode adjustment.
    if (pmssPermissionTargetExists('/etc/openvpn')) {
        $exitCodes[] = runStep('Restricting OpenVPN configs', "find /etc/openvpn -maxdepth 1 -type f -name '*.conf' -not -perm 0640 -exec chmod 640 {} +");
        pmssEnsureRootOwnership('/etc/openvpn', $exitCodes);
        $exitCodes[] = runStep('Ensuring OpenVPN configs owned by root', "find /etc/openvpn -maxdepth 1 -type f -name '*.conf' \\( -not -user root -o -not -group root \\) -exec chown root:root {} +");
    }

    // Child-file work remains gated by the WireGuard parent directory.
    if (pmssPermissionTargetExists('/etc/wireguard')) {
        pmssEnsureRootOwnership('/etc/wireguard', $exitCodes);
        $exitCodes[] = runStep('Hardening WireGuard config directory', 'chmod 700 /etc/wireguard');
        foreach (['wg0.conf', 'server_private.key', 'server_public.key'] as $file) {
            $path = '/etc/wireguard/'.$file;
            pmssPermissionStepsRun($path, $file === 'server_public.key' ? [] : [['Restricting WireGuard '.$file, 'chmod 600 '.$path]], $exitCodes, true, false);
        }
        $exitCodes[] = runStep('Restricting WireGuard key material', "find /etc/wireguard -type f -name '*.key' -not -perm 0600 -exec chmod 600 {} +");
    }
}
