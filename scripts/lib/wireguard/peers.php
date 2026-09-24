<?php
/**
 * WireGuard peer registration and address assignment.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Validate that the supplied string is a base64-encoded 32-byte public key.
 */
function wgValidatePublicKey(string $key): bool
{
    $key = trim($key);
    if ($key === '') {
        return false;
    }
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
    $config = pmssWireguardConfigLines($configPath);
    if ($config['status'] !== 'ok') {
        return ['status' => $config['status'], 'keys' => []];
    }

    $keys = [];
    foreach ($config['lines'] as $line) {
        if (preg_match('/^PublicKey\s*=\s*([A-Za-z0-9+\/=]+)\s*$/', trim($line), $matches) !== 1 || !wgValidatePublicKey($matches[1])) {
            continue;
        }
        $keys[$matches[1]] = true;
    }

    $keys = array_keys($keys);
    sort($keys, SORT_STRING);

    return ['status' => 'ok', 'keys' => $keys];
}

/**
 * Read the valid WireGuard public keys registered for a single user.
 *
 * @return array<int,string>
 */
function wgReadUserPublicKeys(string $user): array
{
    if (!pmssValidateUsername($user)) {
        wgLog('Ignoring WireGuard public keys for invalid user '.$user);
        return [];
    }

    $path = wgUserFilePath($user, '.wireguard-public-key');
    if (!file_exists($path)) {
        return [];
    }
    if (!pmssRegularFilePathIsReadable($path)) {
        wgLog('Ignoring unsafe WireGuard public key path for user '.$user.': '.$path);
        return [];
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        wgLog('Failed to read '.$path.' for user '.$user);
        return [];
    }

    $result = [];
    foreach ($lines as $index => $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }
        if (!wgValidatePublicKey($line)) {
            wgLog(sprintf('Ignoring invalid WireGuard public key for user %s at %s line %d', $user, $path, $index + 1));
            continue;
        }
        $result[] = $line;
    }

    return $result;
}

/**
 * Derive a stable /32 address under 10.90.90.0/24 for a given public key.
 *
 * @param array<string,bool> $usedIps
 */
function wgDeriveClientIp(string $key, array $usedIps): string
{
    $hash = hash('sha256', $key, true);
    $num  = unpack('N', substr($hash, 0, 4));
    $base = (int) ($num[1] ?? 1);
    if ($base === 0) {
        $base = 1;
    }

    // Reserve .1 for the server and avoid network/broadcast addresses.
    $candidate = ($base % 253) + 2; // 2..254
    $tries     = 0;
    while ($tries < 253) {
        $ip = '10.90.90.'.$candidate;
        if (!isset($usedIps[$ip])) {
            return $ip;
        }
        $candidate = ($candidate % 253) + 2;
        $tries++;
    }

    // Extremely unlikely with typical tenant counts; fail-soft by skipping the key.
    return '';
}

/**
 * Collect registered keys and attach deterministic client IPs once per run.
 *
 * @return array{entriesFound:bool,assigned:array<int,array{user:string,key:string,ip:string}>}
 */
function wgPeerState(): array
{
    $used         = [];
    $entriesFound = false;
    $assigned     = [];

    foreach (wgListHomeUsers() as $user) {
        if ($user === '') {
            continue;
        }
        foreach (wgReadUserPublicKeys($user) as $key) {
            $entriesFound = true;
            $ip = wgDeriveClientIp($key, $used);
            if ($ip === '') {
                wgLog('Unable to assign WireGuard IP for user '.$user.' (exhausted address space?)');
                continue;
            }
            $used[$ip] = true;
            $assigned[] = ['user' => $user, 'key' => $key, 'ip' => $ip];
        }
    }

    return ['entriesFound' => $entriesFound, 'assigned' => $assigned];
}

/**
 * Render auto-managed peer sections from collected public keys.
 */
function wgBuildPeersConfig(?array $peerState = null): string
{
    $peerState = $peerState ?? wgPeerState();
    if (!$peerState['entriesFound']) {
        return "# No WireGuard peers configured; place public key(s) in ~/.wireguard-public-key on each user account.\n";
    }

    if (empty($peerState['assigned'])) {
        return "# No valid WireGuard peers configured; all provided keys were invalid.\n";
    }

    $lines   = [];
    $lines[] = '# Peers managed by PMSS – do not edit by hand.';

    foreach ($peerState['assigned'] as $entry) {
        $lines[] = '[Peer]';
        $lines[] = '# user='.$entry['user'];
        $lines[] = 'PublicKey = '.$entry['key'];
        $lines[] = 'AllowedIPs = '.$entry['ip'].'/32';
        $lines[] = '';
    }

    return rtrim(implode("\n", $lines))."\n";
}
