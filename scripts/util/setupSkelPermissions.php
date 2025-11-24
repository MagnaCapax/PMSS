#!/usr/bin/php
<?php
/**
 * Normalise skeleton and configuration permissions so hosts converge on the
 * secure baseline expected by provisioning. Intended for idempotent reruns
 * during updates and manual recovery.
 *
 * Updated: 2025 — includes basic secrets hardening for common private key
 * locations to ensure minimum required permissions are applied.
 */
#TODO Wrong naming etc.

require_once __DIR__.'/../lib/logger.php';
require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/update/runtime/commands.php';

requireRoot();

/**
 * Apply directory hardening steps when the target exists; log a skip otherwise.
 */
function pmssHardenDirectoryPermissions(
    string $path,
    string $contentMessage,
    string $contentCommand,
    string $directoryMessage,
    string $directoryCommand,
    array &$exitCodes
): void {
    if (!is_dir($path)) {
        logmsg(sprintf('Skipping %s permission adjustments; directory missing', $path));
        return;
    }

    $exitCodes[] = runStep($contentMessage, $contentCommand);
    $exitCodes[] = runStep($directoryMessage, $directoryCommand);
}

$exitCodes = [];

pmssHardenDirectoryPermissions(
    '/etc/skel',
    'Hardening /etc/skel content permissions',
    'cd /etc/skel && find . -mindepth 1 -exec chmod -R o-w -- {} +',
    'Restricting /etc/skel directory permissions',
    'chmod 770 /etc/skel',
    $exitCodes
); // not using 775 because there might be places where the perms differ and need to differ

pmssHardenDirectoryPermissions(
    '/etc/seedbox',
    'Hardening /etc/seedbox content permissions',
    'cd /etc/seedbox && find . -mindepth 1 -exec chmod -R o-w -- {} +',
    'Ensuring /etc/seedbox is traversable',
    'chmod o+x /etc/seedbox',
    $exitCodes
); // not using 775 because there might be places where the perms differ and need to differ

// Setup openvpn config perms
if (is_dir('/etc/openvpn')) {
    @chmod('/etc/openvpn', 0771);
}
if (is_file('/etc/openvpn/openvpn.conf')) {
    @chmod('/etc/openvpn/openvpn.conf', 0640);
}
if (is_dir('/etc/openvpn/easy-rsa')) {
    @chmod('/etc/openvpn/easy-rsa', 0771);
}
if (is_file('/etc/seedbox/config/localnet')) {
    @chmod('/etc/seedbox/config/localnet', 0664);
}
@chmod('/etc/seedbox/localnet', 0664);

// Normalise permissions inside /etc/seedbox/config so templates stay readable without leaking secrets.
$configDir = '/etc/seedbox/config';
if (is_dir($configDir)) {
    // Secrets get a tighter mask; everything else falls back to group writable templates.
    $restrictedFiles = [
        $configDir . '/api.localKey'  => 0600,
        $configDir . '/api.remoteKey' => 0600,
    ];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($configDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $node) {
        $path = $node->getPathname();

        if ($node->isDir()) {
            @chmod($path, 0775);
            continue;
        }

        if (isset($restrictedFiles[$path])) {
            @chmod($path, $restrictedFiles[$path]);
            continue;
        }

        @chmod($path, 0664);
    }

    // Ensure the root directory keeps execute permission for traversal.
    @chmod($configDir, 0775);
}

// Minimal secrets audit: tighten common private key locations (best-effort).
// Keep scope narrow and commands idempotent for stability.
if (is_dir('/etc/letsencrypt/live')) {
    runStep('Hardening TLS private keys (Let\'s Encrypt)', "find /etc/letsencrypt/live -type f -name 'privkey.pem' -exec chmod 600 {} +");
}
if (is_dir('/etc/seedbox/config/ssl')) {
    runStep('Hardening seedbox SSL private keys', "find /etc/seedbox/config/ssl -type f -name 'privkey.pem' -exec chmod 600 {} +");
}
if (is_dir('/etc/openvpn/easy-rsa/pki/private')) {
    runStep('Hardening OpenVPN private keys', "find /etc/openvpn/easy-rsa/pki/private -type f -name '*.key' -exec chmod 600 {} +");
}

// WireGuard hardening: restrict directory and sensitive files
if (is_dir('/etc/wireguard')) {
    runStep('Hardening WireGuard config directory', 'chmod 700 /etc/wireguard');
    if (is_file('/etc/wireguard/wg0.conf')) {
        runStep('Restricting WireGuard wg0.conf', 'chmod 600 /etc/wireguard/wg0.conf');
    }
    if (is_file('/etc/wireguard/server_private.key')) {
        runStep('Restricting WireGuard server_private.key', 'chmod 600 /etc/wireguard/server_private.key');
    }
}

$failed = array_filter($exitCodes, static function ($rc) { return $rc !== 0; });
exit($failed ? 1 : 0);
