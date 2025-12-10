#!/usr/bin/php -q
<?php
/**
 * WireGuard peer status helper.
 *
 * Lists peers managed by PMSS (derived from wg0.conf) along with their
 * assigned /32 address and basic connection status from `wg show wg0 dump`.
 */

define('PMSS_WIREGUARD_NO_ENTRYPOINT', true);

require_once __DIR__.'/lib/wireguard.php';

/**
 * Parse wg0.conf and extract peers with user tags, public keys, and AllowedIPs.
 *
 * @return array<int,array{user:string,key:string,ip:string}>
 */
function wgLoadConfiguredPeers(): array
{
    $configPath = wgConfigPath('wg0.conf');
    if (!is_file($configPath)) {
        fwrite(STDERR, "WireGuard config not found at {$configPath}\n");
        return [];
    }

    $lines = @file($configPath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        fwrite(STDERR, "Unable to read {$configPath}\n");
        return [];
    }

    $peers       = [];
    $currentUser = '';
    $currentKey  = '';
    $currentIp   = '';

    $flushPeer = function () use (&$peers, &$currentUser, &$currentKey, &$currentIp): void {
        if ($currentKey !== '' && $currentIp !== '') {
            $peers[] = [
                'user' => $currentUser !== '' ? $currentUser : '-',
                'key'  => $currentKey,
                'ip'   => $currentIp,
            ];
        }
        $currentUser = '';
        $currentKey  = '';
        $currentIp   = '';
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }
        if (strpos($trimmed, '[Peer]') === 0) {
            $flushPeer();
            continue;
        }
        if (strpos($trimmed, '# user=') === 0) {
            $currentUser = trim(substr($trimmed, strlen('# user=')));
            continue;
        }
        if (stripos($trimmed, 'PublicKey =') === 0) {
            $currentKey = trim(substr($trimmed, strlen('PublicKey =')));
            continue;
        }
        if (stripos($trimmed, 'AllowedIPs =') === 0) {
            $value = trim(substr($trimmed, strlen('AllowedIPs =')));
            // Take the first CIDR entry before any comma.
            $parts = explode(',', $value, 2);
            $cidr  = trim($parts[0]);
            $ipParts = explode('/', $cidr, 2);
            $currentIp = trim($ipParts[0]);
            continue;
        }
    }

    $flushPeer();

    return $peers;
}

/**
 * Load runtime status from `wg show wg0 dump`, keyed by public key.
 *
 * @return array<string,array{endpoint:string,latest:int,rx:string,tx:string}>
 */
function wgLoadRuntimeStatus(): array
{
    $status = [];
    exec('wg show wg0 dump 2>/dev/null', $output, $rc);
    if ($rc !== 0 || empty($output)) {
        return $status;
    }

    foreach ($output as $line) {
        $parts = explode("\t", trim($line));
        if (count($parts) < 3) {
            continue;
        }
        // The first field on peer lines is the public key.
        // Interface lines are ignored.
        if (strpos($parts[0], 'public-key') !== false || strpos($parts[0], 'interface') !== false) {
            continue;
        }
        // Heuristic: peer lines have at least 8 columns; public key is column 1.
        if (count($parts) < 8) {
            continue;
        }
        $pubkey   = $parts[0];
        $endpoint = $parts[1];
        $latest   = (int) $parts[4];
        $rx       = $parts[5];
        $tx       = $parts[6];

        $status[$pubkey] = [
            'endpoint' => $endpoint,
            'latest'   => $latest,
            'rx'       => $rx,
            'tx'       => $tx,
        ];
    }

    return $status;
}

$peers  = wgLoadConfiguredPeers();
$status = wgLoadRuntimeStatus();

if (empty($peers)) {
    echo "No WireGuard peers configured.\n";
    exit(0);
}

echo str_pad('USER', 16)
    .str_pad('ADDRESS', 18)
    .str_pad('CONNECTED', 12)
    .str_pad('RX (bytes)', 14)
    .str_pad('TX (bytes)', 14)
    ."ENDPOINT\n";

foreach ($peers as $peer) {
    $key   = $peer['key'];
    $ip    = $peer['ip'];
    $user  = $peer['user'];

    $entry     = $status[$key] ?? null;
    $connected = 'no';
    $rx        = '-';
    $tx        = '-';
    $endpoint  = '-';

    if (is_array($entry)) {
        $endpoint = $entry['endpoint'] !== '' ? $entry['endpoint'] : '-';
        $rx       = $entry['rx'];
        $tx       = $entry['tx'];
        if ($entry['latest'] > 0) {
            $connected = 'yes';
        }
    }

    echo str_pad($user, 16)
        .str_pad($ip, 18)
        .str_pad($connected, 12)
        .str_pad($rx, 14)
        .str_pad($tx, 14)
        .$endpoint."\n";
}
