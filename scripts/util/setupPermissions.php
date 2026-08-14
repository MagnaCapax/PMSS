#!/usr/bin/env php
<?php
/**
 * Normalise system, skeleton, and configuration permissions so hosts converge
 * on the secure baseline expected by provisioning. Intended for idempotent
 * reruns during updates and manual recovery.
 *
 * Updated: 2025 — includes basic secrets hardening for common private key
 * locations to ensure minimum required permissions are applied.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../lib/update/runtime/commands.php';

requireRoot();

/**
 * Enforce root ownership on a target path when present.
 */
function pmssEnsureRootOwnership(string $path, array &$exitCodes): void
{
    if (pmssSkipSymlink($path)) {
        return;
    }

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
 * Confirm a managed permission directory exists without following symlinks.
 */
function pmssPermissionTargetDirectoryExists(string $path): bool
{
    return !pmssSkipSymlink($path) && is_dir($path);
}

/**
 * Confirm a managed permission file exists without following symlinks.
 */
function pmssPermissionTargetFileExists(string $path): bool
{
    return !pmssSkipSymlink($path) && is_file($path);
}

/**
 * Run one or more commands for a directory when present and not symlinked.
 */
function pmssRunDirectorySteps(string $path, array $steps, array &$exitCodes): void
{
    if (!pmssPermissionTargetDirectoryExists($path)) {
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
    if (!pmssPermissionTargetDirectoryExists($path)) {
        return;
    }

    pmssEnsureRootOwnership($path, $exitCodes);
    $exitCodes[] = runStep($message, $command);
}

// Table-driven to keep skeleton/config hardening steps aligned and avoid drift.
$permissionTargets = [
    '/etc/skel' => [
        'content'   => ['Hardening /etc/skel content permissions', 'find /etc/skel -mindepth 1 -not -type l -perm /002 -exec chmod o-w -- {} +'],
        'directory' => ['Restricting /etc/skel directory permissions', 'chmod 770 /etc/skel'],
        'files'     => ['Removing other permissions from /etc/skel files', "find /etc/skel -type f -perm /007 -exec chmod o-rwx -- {} +"],
    ],
    '/etc/seedbox' => [
        'content'   => ['Hardening /etc/seedbox content permissions', 'find /etc/seedbox -mindepth 1 -not -type l -perm /002 -exec chmod o-w -- {} +'],
        'directory' => ['Ensuring /etc/seedbox is traversable', 'chmod o+x /etc/seedbox'],
        'files'     => ['Removing other permissions from /etc/seedbox files', "find /etc/seedbox -type f -perm /007 -exec chmod o-rwx -- {} +"],
    ],
];

$exitCodes = [];

foreach ($permissionTargets as $path => $target) {
    if (!pmssPermissionTargetDirectoryExists($path)) {
        if (!is_link($path)) {
            logmsg(sprintf('Skipping %s permission adjustments; directory missing', $path));
        }
        continue;
    }

    $exitCodes[] = runStep($target['content'][0], $target['content'][1]);
    $exitCodes[] = runStep($target['directory'][0], $target['directory'][1]);
    // not using 775 because there might be places where the perms differ and need to differ
}

foreach ($permissionTargets as $path => $target) {
    pmssEnsureRootOwnership($path, $exitCodes);
}

foreach ($permissionTargets as $path => $target) {
    if (pmssPermissionTargetDirectoryExists($path)) {
        $exitCodes[] = runStep($target['files'][0], $target['files'][1]);
    }
}

// Setup openvpn config perms
if (pmssPermissionTargetDirectoryExists('/etc/openvpn')) {
    @chmod('/etc/openvpn', 0771);
}
if (pmssPermissionTargetFileExists('/etc/openvpn/openvpn.conf')) {
    @chmod('/etc/openvpn/openvpn.conf', 0640);
}
if (pmssPermissionTargetDirectoryExists('/etc/openvpn/easy-rsa')) {
    @chmod('/etc/openvpn/easy-rsa', 0771);
}
if (pmssPermissionTargetFileExists('/etc/seedbox/config/localnet')) {
    @chmod('/etc/seedbox/config/localnet', 0664);
}
if (pmssPermissionTargetFileExists('/etc/seedbox/localnet')) {
    @chmod('/etc/seedbox/localnet', 0664);
}

// Normalise permissions inside /etc/seedbox/config so templates stay readable without leaking secrets.
$configDir = '/etc/seedbox/config';
if (pmssPermissionTargetDirectoryExists($configDir)) {
    // No committed secrets remain in /etc/seedbox/config: the dead api.* command-and-control
    // family (config + generator setupApiKey.php + deleted consumer serverApi.php) was removed.
    // If a live secret is reintroduced, add it here with a 0600 mask.
    $restrictedFiles = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($configDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $node) {
        $path = $node->getPathname();
        if ($node->isLink()) {
            logmsg(sprintf('Skipping %s: symlink detected', $path));
            continue;
        }

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

foreach ($letsencryptSteps as $path => $steps) {
    pmssRunDirectorySteps($path, $steps, $exitCodes);
}

pmssOwnAndRestrictDirectory('/var/lib/letsencrypt', 'Restricting /var/lib/letsencrypt', 'chmod 700 /var/lib/letsencrypt', $exitCodes);
pmssOwnAndRestrictDirectory('/var/log/letsencrypt', 'Restricting /var/log/letsencrypt', 'chmod 700 /var/log/letsencrypt', $exitCodes);
if (pmssPermissionTargetDirectoryExists('/etc/seedbox/config/ssl')) {
    $exitCodes[] = runStep('Hardening seedbox SSL private keys', "find /etc/seedbox/config/ssl -type f -name 'privkey.pem' -not -perm 0600 -exec chmod 600 {} +");
}
if (pmssPermissionTargetDirectoryExists('/etc/openvpn/easy-rsa/pki/private')) {
    pmssEnsureRootOwnership('/etc/openvpn/easy-rsa/pki/private', $exitCodes);
    $exitCodes[] = runStep('Restricting OpenVPN private key directory', 'chmod 700 /etc/openvpn/easy-rsa/pki/private');
    $exitCodes[] = runStep('Hardening OpenVPN private keys', "find /etc/openvpn/easy-rsa/pki/private -type f -name '*.key' -not -perm 0600 -exec chmod 600 {} +");
}
foreach (['/etc/openvpn/easy-rsa/pki/issued', '/etc/openvpn/easy-rsa/pki/reqs', '/etc/openvpn/easy-rsa/pki/crl', '/etc/openvpn/easy-rsa/pki/certs_by_serial'] as $dir) {
    if (pmssPermissionTargetDirectoryExists($dir)) {
        pmssEnsureRootOwnership($dir, $exitCodes);
        $exitCodes[] = runStep('Restricting OpenVPN PKI directory '.$dir, 'chmod 750 '.escapeshellarg($dir));
    }
}
if (pmssPermissionTargetFileExists('/etc/openvpn/easy-rsa/pki/crl.pem')) {
    pmssEnsureRootOwnership('/etc/openvpn/easy-rsa/pki/crl.pem', $exitCodes);
    $exitCodes[] = runStep('Restricting OpenVPN CRL', 'chmod 600 /etc/openvpn/easy-rsa/pki/crl.pem');
}
if (pmssPermissionTargetFileExists('/etc/openvpn/ta.key')) {
    pmssEnsureRootOwnership('/etc/openvpn/ta.key', $exitCodes);
    $exitCodes[] = runStep('Restricting OpenVPN ta.key', 'chmod 600 /etc/openvpn/ta.key');
}
if (pmssPermissionTargetDirectoryExists('/etc/openvpn')) {
    $exitCodes[] = runStep('Restricting OpenVPN configs', "find /etc/openvpn -maxdepth 1 -type f -name '*.conf' -not -perm 0640 -exec chmod 640 {} +");
    pmssEnsureRootOwnership('/etc/openvpn', $exitCodes);
    $exitCodes[] = runStep('Ensuring OpenVPN configs owned by root', "find /etc/openvpn -maxdepth 1 -type f -name '*.conf' \\( -not -user root -o -not -group root \\) -exec chown root:root {} +");
}

// WireGuard hardening: restrict directory and sensitive files
if (pmssPermissionTargetDirectoryExists('/etc/wireguard')) {
    pmssEnsureRootOwnership('/etc/wireguard', $exitCodes);
    $exitCodes[] = runStep('Hardening WireGuard config directory', 'chmod 700 /etc/wireguard');
    if (pmssPermissionTargetFileExists('/etc/wireguard/wg0.conf')) {
        pmssEnsureRootOwnership('/etc/wireguard/wg0.conf', $exitCodes);
        $exitCodes[] = runStep('Restricting WireGuard wg0.conf', 'chmod 600 /etc/wireguard/wg0.conf');
    }
    if (pmssPermissionTargetFileExists('/etc/wireguard/server_private.key')) {
        pmssEnsureRootOwnership('/etc/wireguard/server_private.key', $exitCodes);
        $exitCodes[] = runStep('Restricting WireGuard server_private.key', 'chmod 600 /etc/wireguard/server_private.key');
    }
    if (pmssPermissionTargetFileExists('/etc/wireguard/server_public.key')) {
        pmssEnsureRootOwnership('/etc/wireguard/server_public.key', $exitCodes);
    }
    $exitCodes[] = runStep('Restricting WireGuard key material', "find /etc/wireguard -type f -name '*.key' -not -perm 0600 -exec chmod 600 {} +");
}

$failed = array_filter($exitCodes, static function ($rc) { return $rc !== 0; });
exit($failed ? 1 : 0);
