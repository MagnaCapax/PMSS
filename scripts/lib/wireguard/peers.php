<?php
/**
 * WireGuard public-key validation and account registry reads.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/peerAssignment.php';

/**
 * Validate that the supplied string is a base64-encoded 32-byte public key.
 */
function wgValidatePublicKey(string $key): bool
{
    $key = trim($key);
    if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $key)) {
        return false;
    }
    $decoded = base64_decode($key, true);
    return $decoded !== false && strlen($decoded) === 32;
}

/**
 * Read configured peer public keys from wg0.conf.
 *
 * @return array{status:string,keys:array<int,string>}
 */
function pmssWireguardPeerPublicKeysFromConfig(string $configPath): array
{
    return pmssWireguardConfigValuesRead($configPath, 'keys', '/^PublicKey\s*=\s*([A-Za-z0-9+\/=]+)\s*$/',
        'wgValidatePublicKey', SORT_STRING);
}

/**
 * Read the valid WireGuard public keys registered for a single user.
 *
 * @return array<int,string>
 */
function wgReadUserPublicKeysResult(string $user): array
{
    if (!pmssValidateUsername($user)) {
        wgLog('Ignoring WireGuard public keys for invalid user '.$user);
        return ['status' => 'error', 'keys' => []];
    }

    $path = wgUserFilePath($user, '.wireguard-public-key');
    if (!pmssPathExistsOrLink($path)) {
        return ['status' => 'absent', 'keys' => []];
    }

    $contents = pmssReadRegularFileContentsVerified($path, null, 65536);
    if ($contents === null) {
        wgLog('Unable to read WireGuard public key file for user '.$user);
        return ['status' => 'error', 'keys' => []];
    }

    $result = [];
    foreach (pmssConfigTextLines($contents, ['#', ';']) as $index => $line) {
        if (!wgValidatePublicKey($line)) {
            wgLog(sprintf('Ignoring invalid WireGuard public key for user %s at %s line %d', $user, $path, $index + 1));
            continue;
        }
        $result[$line] = true;
    }

    return ['status' => 'ok', 'keys' => array_keys($result)];
}

/** Retain the original key-only reader contract for existing callers. */
function wgReadUserPublicKeys(string $user): array
{
    return wgReadUserPublicKeysResult($user)['keys'];
}
