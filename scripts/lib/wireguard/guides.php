<?php
/**
 * Per-user WireGuard guide provisioning.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Replace one guide line while preserving the original text on regex errors. */
function wgGuideReplaceFirst(string $content, string $pattern, string $replacement): string
{
    $updated = preg_replace($pattern, $replacement, $content, 1);
    return $updated === null ? $content : $updated;
}

/**
 * Resolve a WireGuard-managed file inside a user's home directory.
 */
function wgUserFilePath(string $user, string $filename): string
{
    $homeBase = pmssResolvePathFromEnv('PMSS_WG_HOME_BASE', '/home');
    return $homeBase.'/'.$user.'/'.$filename;
}

/**
 * Replace the placeholder client private key in a user guide.
 */
function wgApplyPrivateKeyToGuide(string $content, string $privateKey): string
{
    return wgGuideReplaceFirst(
        $content,
        '/^PrivateKey = <client private key>$/m',
        'PrivateKey = '.$privateKey
    );
}

/**
 * Determine whether the guide still matches the PMSS-managed bootstrap profile.
 */
function wgGuideLooksManagedBootstrapProfile(string $content): bool
{
    return preg_match('/^MTU = 1420$/m', $content) === 1
        && preg_match('/^DNS = 1\.1\.1\.1$/m', $content) === 1
        && preg_match('/^AllowedIPs = 0\.0\.0\.0\/0, ::\/0$/m', $content) === 1
        && preg_match('/^PersistentKeepalive = 25$/m', $content) === 1;
}

/**
 * Read the client private key from a ready-to-import guide.
 */
function wgGuidePrivateKey(string $content): string
{
    if (preg_match('/^PrivateKey = ([^\r\n]+)$/m', $content, $matches) !== 1) {
        return '';
    }

    $privateKey = trim($matches[1]);
    if ($privateKey === '' || $privateKey === '<client private key>') {
        return '';
    }

    return $privateKey;
}

/**
 * Build a ready-to-import client configuration template.
 */
function wgBuildClientGuide(string $publicKey, string $endpoint, int $listenPort): string
{
    return "[Interface]\n"
        ."PrivateKey = <client private key>\n"
        ."Address = 10.90.90.X/32\n"
        ."MTU = 1420\n"
        ."DNS = 1.1.1.1\n\n"
        ."[Peer]\n"
        ."PublicKey = {$publicKey}\n"
        ."Endpoint = {$endpoint}:{$listenPort}\n"
        ."AllowedIPs = 0.0.0.0/0, ::/0\n"
        ."PersistentKeepalive = 25\n";
}

/**
 * Generate a client keypair for the ready-to-import bootstrap profile.
 *
 * Environment overrides are available for hermetic tests.
 *
 * @return array{0:string,1:string}
 */
function wgGenerateClientKeypair(): array
{
    $privateKey = wgKeyMaterialResolve(
        'PMSS_WG_CLIENT_PRIVATE_KEY',
        'wg genkey',
        'Failed to generate WireGuard client private key'
    );
    if ($privateKey === '') {
        return ['', ''];
    }

    $publicKey = wgDerivePublicKey($privateKey);
    if ($publicKey === '') {
        return ['', ''];
    }

    return [$privateKey, $publicKey];
}

/**
 * Derive a client public key from private key material.
 */
function wgDerivePublicKey(string $privateKey): string
{
    $publicKey = wgKeyMaterialResolve(
        'PMSS_WG_CLIENT_PUBLIC_KEY',
        'printf %s '.escapeshellarg($privateKey).' | wg pubkey',
        'Failed to derive WireGuard client public key'
    );
    if ($publicKey === '' || !wgValidatePublicKey($publicKey)) {
        if ($publicKey === '') {
            return '';
        }
        wgLog('Failed to derive WireGuard client public key');
        return '';
    }

    return $publicKey;
}

/** Replace the placeholder client address with the assigned IP. */
function wgApplyAssignedIpToGuide(string $content, string $ip): string
{
    $content = wgGuideReplaceFirst(
        $content,
        '/^Address = 10\.90\.90\.(?:X|[0-9]{1,3})\/32$/m',
        'Address = '.$ip.'/32'
    );

    return wgGuideReplaceFirst(
        $content,
        '/AllowedIPs = 10\.90\.90\.(?:X|[0-9]{1,3})\/32/',
        'AllowedIPs = '.$ip.'/32'
    );
}

/**
 * Ensure a default single-device client profile exists for users without keys.
 */
function wgBootstrapUserGuide(string $user, string $clientGuide): void
{
    $publicKeyPath = wgUserFilePath($user, '.wireguard-public-key');
    $guidePath     = wgUserFilePath($user, 'wireguard.txt');
    $guideExists   = is_file($guidePath);
    $guide         = $guideExists ? @file_get_contents($guidePath) : false;
    $publicKeyText = is_file($publicKeyPath) ? @file_get_contents($publicKeyPath) : false;
    $managedGuide  = $guide !== false
        && $guide !== ''
        && preg_match('/^PrivateKey = <client private key>$/m', $guide) !== 1;

    if (!empty(wgReadUserPublicKeys($user))) {
        if (!$managedGuide && $clientGuide !== '' && !pmssWriteUserFile($guidePath, $clientGuide, $user, 0600)) {
            wgLog('Failed to distribute WireGuard guide for user '.$user);
        }
        return;
    }
    if ($managedGuide && !wgGuideLooksManagedBootstrapProfile($guide)) {
        return;
    }

    $originalGuide = $guideExists && $guide !== false ? $guide : null;
    if ($managedGuide) {
        $privateKey = wgGuidePrivateKey($guide);
        if ($privateKey === '') {
            wgLog('Managed WireGuard guide for user '.$user.' is missing a client private key');
            return;
        }
        $publicKey = wgDerivePublicKey($privateKey);
        if ($publicKey === '') {
            return;
        }
        $updatedGuide = $guide;
    } else {
        [$privateKey, $publicKey] = wgGenerateClientKeypair();
        if ($privateKey === '' || $publicKey === '') {
            return;
        }
        $updatedGuide = wgApplyPrivateKeyToGuide($clientGuide, $privateKey);
    }

    $updatedKeyText = $publicKey.PHP_EOL;
    if ($publicKeyText !== false && trim($publicKeyText) !== '') {
        $updatedKeyText = rtrim($publicKeyText, "\r\n").PHP_EOL.$publicKey.PHP_EOL;
    }

    if (!$managedGuide && !pmssWriteUserFile($guidePath, $updatedGuide, $user, 0600)) {
        wgLog('Failed to write WireGuard guide for user '.$user);
        return;
    }

    if (!pmssWriteUserFile($publicKeyPath, $updatedKeyText, $user, 0600)) {
        wgLog('Failed to write WireGuard public key for user '.$user);
        if (!$managedGuide && $originalGuide === null) {
            if (pmssUserFilePathIsSafe($guidePath) && is_file($guidePath) && !@unlink($guidePath)) {
                wgLog('Failed to remove incomplete WireGuard guide for user '.$user);
            }
        } elseif (!$managedGuide && !pmssWriteUserFile($guidePath, $originalGuide, $user, 0600)) {
            wgLog('Failed to restore WireGuard guide for user '.$user.' after public key write failure');
        }
        return;
    }

    if ($managedGuide) {
        wgLog('Recovered WireGuard public key registration for user '.$user.' from existing managed guide');
        pmssUserFileApplyMetadata($guidePath, $user, 0600);
    }
}

/**
 * Update each per-user guide with the IP assigned to its embedded private key.
 *
 * @param array<int,array{user:string,key:string,ip:string}> $assigned
 */
function wgSyncUserGuideAddresses(array $assigned, string $fallbackGuide = ''): void
{
    if (empty($assigned)) {
        return;
    }

    $seenUsers = [];

    foreach ($assigned as $entry) {
        if (isset($seenUsers[$entry['user']])) {
            continue;
        }

        $target       = wgUserFilePath($entry['user'], 'wireguard.txt');
        $targetExists = is_file($target);
        $guide        = $targetExists ? @file_get_contents($target) : false;
        if ($guide === false || $guide === '') {
            if ($fallbackGuide === '') {
                continue;
            }
            $guide = $fallbackGuide;
        }
        $privateKey = wgGuidePrivateKey($guide);
        if ($privateKey === '' || wgDerivePublicKey($privateKey) !== $entry['key']) {
            continue;
        }
        $seenUsers[$entry['user']] = true;

        $updated = wgApplyAssignedIpToGuide($guide, $entry['ip']);
        if ($targetExists && $updated === $guide) {
            continue;
        }
        if (!pmssWriteUserFile($target, $updated, $entry['user'], 0600)) {
            wgLog('Failed to update WireGuard guide for user '.$entry['user']);
            continue;
        }
    }
}

/**
 * Reconcile user guides, registered keys, and assigned addresses.
 *
 * @return array{entriesFound:bool,assigned:array}
 */
function wgClientStateReconcile(string $publicKey, string $endpoint, int $listenPort): array
{
    $guide = wgBuildClientGuide($publicKey, $endpoint, $listenPort);
    foreach (wgListHomeUsers() as $user) {
        if ($user !== '') {
            wgBootstrapUserGuide($user, $guide);
        }
    }

    $peerState = wgPeerState();
    wgSyncUserGuideAddresses($peerState['assigned'], $guide);

    return $peerState;
}
