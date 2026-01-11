#!/usr/bin/env php
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

/**
 * Enforce root ownership on a target path when present.
 */
function pmssEnsureRootOwnership(string $path, array &$exitCodes): void
{
    if (!file_exists($path)) {
        logmsg(sprintf('Skipping ownership enforcement; missing %s', $path));
        return;
    }

    $exitCodes[] = runStep(
        sprintf('Ensuring %s ownership is root:root', $path),
        'chown root:root '.escapeshellarg($path)
    );
}

/**
 * Skip when the path is a symlink to avoid hardening unexpected destinations.
 */
function pmssSkipSymlink(string $path): bool
{
    if (is_link($path)) {
        logmsg(sprintf('Skipping %s: symlink detected', $path));
        return true;
    }
    return false;
}

/**
 * Run one or more commands for a directory when present and not symlinked.
 */
function pmssRunDirectorySteps(string $path, array $steps, array &$exitCodes): void
{
    if (pmssSkipSymlink($path) || !is_dir($path)) {
        return;
    }

    foreach ($steps as $step) {
        [$message, $command] = $step;
        $exitCodes[] = runStep($message, $command);
    }
}

/**
 * Enforce root ownership and run a chmod for a directory when present.
 */
function pmssOwnAndRestrictDirectory(string $path, string $message, string $command, array &$exitCodes): void
{
    if (pmssSkipSymlink($path) || !is_dir($path)) {
        return;
    }

    pmssEnsureRootOwnership($path, $exitCodes);
    $exitCodes[] = runStep($message, $command);
}

// Table-driven to keep skeleton/config hardening steps aligned and avoid drift.
$permissionTargets = [
    '/etc/skel' => [
        'content'   => ['Hardening /etc/skel content permissions', 'cd /etc/skel && find . -mindepth 1 -exec chmod -R o-w -- {} +'],
        'directory' => ['Restricting /etc/skel directory permissions', 'chmod 770 /etc/skel'],
        'files'     => ['Removing other permissions from /etc/skel files', "find /etc/skel -type f -exec chmod o-rwx -- {} +"],
    ],
    '/etc/seedbox' => [
        'content'   => ['Hardening /etc/seedbox content permissions', 'cd /etc/seedbox && find . -mindepth 1 -exec chmod -R o-w -- {} +'],
        'directory' => ['Ensuring /etc/seedbox is traversable', 'chmod o+x /etc/seedbox'],
        'files'     => ['Removing other permissions from /etc/seedbox files', "find /etc/seedbox -type f -exec chmod o-rwx -- {} +"],
    ],
];

$exitCodes = [];

foreach ($permissionTargets as $path => $target) {
    pmssHardenDirectoryPermissions(
        $path,
        $target['content'][0],
        $target['content'][1],
        $target['directory'][0],
        $target['directory'][1],
        $exitCodes
    ); // not using 775 because there might be places where the perms differ and need to differ
}

foreach ($permissionTargets as $path => $target) {
    pmssEnsureRootOwnership($path, $exitCodes);
}

foreach ($permissionTargets as $path => $target) {
    if (is_dir($path)) {
        $exitCodes[] = runStep($target['files'][0], $target['files'][1]);
    }
}

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
$letsencryptSteps = [
    '/etc/letsencrypt/live' => [
        ['Hardening TLS private keys (Let\'s Encrypt)', "find /etc/letsencrypt/live -type f -name 'privkey.pem' -exec chmod 600 {} +"],
    ],
    '/etc/letsencrypt/accounts' => [
        ['Hardening TLS account keys (Let\'s Encrypt)', "find /etc/letsencrypt/accounts -type f \\( -name 'private_key.json' -o -name 'private_key*.pem' \\) -exec chmod 600 {} +"],
        ['Hardening TLS account metadata (Let\'s Encrypt)', "find /etc/letsencrypt/accounts -type f \\( -name 'registration.json' -o -name 'meta.json' \\) -exec chmod 600 {} +"],
    ],
    '/etc/letsencrypt/archive' => [
        ['Hardening TLS archive keys (Let\'s Encrypt)', "find /etc/letsencrypt/archive -type f -name 'privkey*.pem' -exec chmod 600 {} +"],
    ],
    '/etc/letsencrypt/renewal' => [
        ['Restricting TLS renewal configs (Let\'s Encrypt)', "find /etc/letsencrypt/renewal -type f -name '*.conf' -exec chmod 600 {} +"],
    ],
];

foreach ($letsencryptSteps as $path => $steps) {
    pmssRunDirectorySteps($path, $steps, $exitCodes);
}

pmssOwnAndRestrictDirectory('/var/lib/letsencrypt', 'Restricting /var/lib/letsencrypt', 'chmod 700 /var/lib/letsencrypt', $exitCodes);
pmssOwnAndRestrictDirectory('/var/log/letsencrypt', 'Restricting /var/log/letsencrypt', 'chmod 700 /var/log/letsencrypt', $exitCodes);
if (is_dir('/etc/seedbox/config/ssl')) {
    $exitCodes[] = runStep('Hardening seedbox SSL private keys', "find /etc/seedbox/config/ssl -type f -name 'privkey.pem' -exec chmod 600 {} +");
}
if (is_dir('/etc/openvpn/easy-rsa/pki/private')) {
    pmssEnsureRootOwnership('/etc/openvpn/easy-rsa/pki/private', $exitCodes);
    $exitCodes[] = runStep('Restricting OpenVPN private key directory', 'chmod 700 /etc/openvpn/easy-rsa/pki/private');
    $exitCodes[] = runStep('Hardening OpenVPN private keys', "find /etc/openvpn/easy-rsa/pki/private -type f -name '*.key' -exec chmod 600 {} +");
}
foreach (['/etc/openvpn/easy-rsa/pki/issued', '/etc/openvpn/easy-rsa/pki/reqs', '/etc/openvpn/easy-rsa/pki/crl', '/etc/openvpn/easy-rsa/pki/certs_by_serial'] as $dir) {
    if (is_dir($dir)) {
        pmssEnsureRootOwnership($dir, $exitCodes);
        $exitCodes[] = runStep('Restricting OpenVPN PKI directory '.$dir, 'chmod 750 '.escapeshellarg($dir));
    }
}
if (is_file('/etc/openvpn/easy-rsa/pki/crl.pem')) {
    pmssEnsureRootOwnership('/etc/openvpn/easy-rsa/pki/crl.pem', $exitCodes);
    $exitCodes[] = runStep('Restricting OpenVPN CRL', 'chmod 600 /etc/openvpn/easy-rsa/pki/crl.pem');
}
if (is_file('/etc/openvpn/ta.key')) {
    pmssEnsureRootOwnership('/etc/openvpn/ta.key', $exitCodes);
    $exitCodes[] = runStep('Restricting OpenVPN ta.key', 'chmod 600 /etc/openvpn/ta.key');
}
if (is_dir('/etc/openvpn')) {
    $exitCodes[] = runStep('Restricting OpenVPN configs', "find /etc/openvpn -maxdepth 1 -type f -name '*.conf' -exec chmod 640 {} +");
    pmssEnsureRootOwnership('/etc/openvpn', $exitCodes);
    $exitCodes[] = runStep('Ensuring OpenVPN configs owned by root', "find /etc/openvpn -maxdepth 1 -type f -name '*.conf' -exec chown root:root {} +");
}

// WireGuard hardening: restrict directory and sensitive files
if (is_dir('/etc/wireguard')) {
    pmssEnsureRootOwnership('/etc/wireguard', $exitCodes);
    $exitCodes[] = runStep('Hardening WireGuard config directory', 'chmod 700 /etc/wireguard');
    if (is_file('/etc/wireguard/wg0.conf')) {
        pmssEnsureRootOwnership('/etc/wireguard/wg0.conf', $exitCodes);
        $exitCodes[] = runStep('Restricting WireGuard wg0.conf', 'chmod 600 /etc/wireguard/wg0.conf');
    }
    if (is_file('/etc/wireguard/server_private.key')) {
        pmssEnsureRootOwnership('/etc/wireguard/server_private.key', $exitCodes);
        $exitCodes[] = runStep('Restricting WireGuard server_private.key', 'chmod 600 /etc/wireguard/server_private.key');
    }
    if (is_file('/etc/wireguard/server_public.key')) {
        pmssEnsureRootOwnership('/etc/wireguard/server_public.key', $exitCodes);
    }
    $exitCodes[] = runStep('Restricting WireGuard key material', "find /etc/wireguard -type f -name '*.key' -exec chmod 600 {} +");
}

$failed = array_filter($exitCodes, static function ($rc) { return $rc !== 0; });
exit($failed ? 1 : 0);
