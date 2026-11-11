<?php
/** WireGuard pin reservation and deterministic address assignment. @license GPL-3.0-only */

const WG_MAX_KEYS_PER_ACCOUNT = 8;

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
