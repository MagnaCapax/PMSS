<?php
/**
 * WireGuard peer registration and address assignment.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

const WG_MAX_KEYS_PER_ACCOUNT = 8;

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
    $seen = [];
    foreach (pmssConfigLines(explode("\n", $contents), ['#', ';']) as $index => $line) {
        if (!wgValidatePublicKey($line)) {
            wgLog(sprintf('Ignoring invalid WireGuard public key for user %s at %s line %d', $user, $path, $index + 1));
            continue;
        }
        if (!isset($seen[$line])) {
            $seen[$line] = true;
            $result[] = $line;
        }
    }

    return ['status' => 'ok', 'keys' => $result];
}

/** Retain the original key-only reader contract for existing callers. */
function wgReadUserPublicKeys(string $user): array
{
    return wgReadUserPublicKeysResult($user)['keys'];
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
    $pins = [];
    $pinOrder = [];
    $pinIps = [];
    $config = pmssWireguardConfigLines(wgConfigDir().'/wg0.conf');
    if ($config['status'] === 'ok') {
        foreach (wgConfigPeerBlocksFromLines($config['lines']) as $peer) {
            $user = $peer['user'];
            $key = $peer['key'];
            $ip = $peer['ip'];
            if (!pmssValidateUsername($user) || !wgValidatePublicKey($key)
                || !preg_match('/^10\.90\.90\.(?:[2-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-4])$/', $ip)) {
                continue;
            }
            if (isset($pins[$user][$key]) || isset($pinIps[$ip])) {
                wgLog('Ignoring duplicate WireGuard peer assignment for user '.$user);
                continue;
            }
            $pins[$user][$key] = $ip;
            $pinIps[$ip] = true;
            $pinOrder[] = ['user' => $user, 'key' => $key, 'ip' => $ip];
        }
    }

    $users = wgListHomeUsers();
    if (!$users && $pinOrder) {
        wgLog('Keeping recorded WireGuard peers while the user list is empty');
        return ['entriesFound' => true, 'assigned' => $pinOrder];
    }

    $keysByUser = [];
    $activePins = [];
    $reserved = [];
    $owners = [];
    foreach ($users as $user) {
        $result = wgReadUserPublicKeysResult($user);
        $keysByUser[$user] = $result['status'] === 'error' ? array_keys($pins[$user] ?? []) : $result['keys'];
        if ($result['status'] === 'error' && isset($pins[$user])) {
            wgLog('Keeping recorded WireGuard peers after key read failed for user '.$user);
        }
        foreach ($keysByUser[$user] as $key) {
            if (!isset($pins[$user][$key])) {
                continue;
            }
            if (isset($owners[$key])) {
                wgLog('Ignoring WireGuard key already assigned to another account');
                continue;
            }
            $ip = $pins[$user][$key];
            $activePins[$user][$key] = $ip;
            $reserved[$ip] = true;
            $owners[$key] = $user;
        }
    }

    $assigned = [];
    foreach ($users as $user) {
        $count = count($activePins[$user] ?? []);
        foreach ($keysByUser[$user] as $key) {
            if (isset($owners[$key]) && $owners[$key] !== $user) {
                wgLog('Ignoring WireGuard key already assigned to another account');
                continue;
            }
            if (isset($activePins[$user][$key])) {
                $ip = $activePins[$user][$key];
            } else {
                if ($count >= WG_MAX_KEYS_PER_ACCOUNT) {
                    wgLog('Ignoring WireGuard key over account limit for user '.$user);
                    continue;
                }
                $ip = wgDeriveClientIp($key, $reserved);
                if ($ip === '') {
                    wgLog('Unable to assign WireGuard IP for user '.$user.' (exhausted address space?)');
                    continue;
                }
                $reserved[$ip] = true;
                $owners[$key] = $user;
                $count++;
            }
            $assigned[] = ['user' => $user, 'key' => $key, 'ip' => $ip];
        }
    }

    return ['entriesFound' => (bool) array_filter($keysByUser) || (bool) $assigned, 'assigned' => $assigned];
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
