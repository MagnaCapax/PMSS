#!/usr/bin/env php
<?php
/**
 * WireGuard peer status helper.
 *
 * Lists peers managed by PMSS (derived from wg0.conf) along with their
 * assigned /32 address and basic connection status from `wg show wg0 dump`.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
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
    $configPath = wgConfigDir().'/wg0.conf';
    if (!is_file($configPath)) {
        fwrite(STDERR, "WireGuard config not found at {$configPath}\n");
        return [];
    }

    $lines = @file($configPath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        fwrite(STDERR, "Unable to read {$configPath}\n");
        return [];
    }

    $peers = [];
    $current = ['user' => '-', 'key' => '', 'ip' => ''];

    foreach (array_merge($lines, ['[Peer]']) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            continue;
        }
        if (strpos($trimmed, '[Peer]') === 0) {
            if ($current['key'] !== '' && $current['ip'] !== '') {
                $peers[] = $current;
            }
            $current = ['user' => '-', 'key' => '', 'ip' => ''];
            continue;
        }
        if (strpos($trimmed, '# user=') === 0) {
            $current['user'] = trim(substr($trimmed, strlen('# user=')));
            if ($current['user'] === '') {
                $current['user'] = '-';
            }
            continue;
        }
        if (stripos($trimmed, 'PublicKey =') === 0) {
            $current['key'] = trim(substr($trimmed, strlen('PublicKey =')));
            continue;
        }
        if (stripos($trimmed, 'AllowedIPs =') === 0) {
            $value = trim(substr($trimmed, strlen('AllowedIPs =')));
            // Take the first CIDR entry before any comma.
            $cidr = trim(explode(',', $value, 2)[0]);
            $current['ip'] = trim(explode('/', $cidr, 2)[0]);
        }
    }

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
        // Interface lines are ignored because peer lines have at least 8 columns.
        if (count($parts) < 8) {
            continue;
        }

        $status[$parts[0]] = [
            'endpoint' => $parts[1],
            'latest'   => (int) $parts[4],
            'rx'       => $parts[5],
            'tx'       => $parts[6],
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
    $entry = $status[$peer['key']] ?? ['endpoint' => '-', 'latest' => 0, 'rx' => '-', 'tx' => '-'];
    $endpoint = $entry['endpoint'] !== '' ? $entry['endpoint'] : '-';

    echo str_pad($peer['user'], 16)
        .str_pad($peer['ip'], 18)
        .str_pad($entry['latest'] > 0 ? 'yes' : 'no', 12)
        .str_pad($entry['rx'], 14)
        .str_pad($entry['tx'], 14)
        .$endpoint."\n";
}
