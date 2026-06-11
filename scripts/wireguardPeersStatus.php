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

$configPath = wgConfigDir().'/wg0.conf';
$peers = [];
$config = pmssWireguardConfigLines($configPath);
if ($config['status'] === 'missing') {
    fwrite(STDERR, "WireGuard config not found at {$configPath}\n");
} elseif ($config['status'] !== 'ok') {
    fwrite(STDERR, "Unable to read {$configPath}\n");
} else {
    foreach (wgConfigPeerBlocksFromLines($config['lines']) as $peer) {
        if ($peer['key'] !== '' && $peer['ip'] !== '') {
            $peer['user'] = $peer['user'] !== '' ? $peer['user'] : '-';
            $peers[] = $peer;
        }
    }
}

$status = [];
exec('wg show wg0 dump 2>/dev/null', $output, $rc);
if ($rc === 0) {
    foreach ($output as $line) {
        $parts = explode("\t", trim($line));
        // Interface lines are ignored because peer lines have at least 8 columns.
        if (count($parts) < 8) {
            continue;
        }

        // wg dump peer fields: pubkey, preshared-key, endpoint, allowed-ips,
        // latest-handshake, rx, tx, keepalive.
        $status[$parts[0]] = [
            'endpoint' => $parts[2],
            'latest'   => (int) $parts[4],
            'rx'       => $parts[5],
            'tx'       => $parts[6],
        ];
    }
}

if (empty($peers)) {
    echo "No WireGuard peers configured.\n";
    exit(0);
}

printf("%-16s%-18s%-12s%-14s%-14s%s\n", 'USER', 'ADDRESS', 'CONNECTED', 'RX (bytes)', 'TX (bytes)', 'ENDPOINT');

foreach ($peers as $peer) {
    $entry = $status[$peer['key']] ?? ['endpoint' => '-', 'latest' => 0, 'rx' => '-', 'tx' => '-'];
    $endpoint = $entry['endpoint'] !== '' ? $entry['endpoint'] : '-';

    printf("%-16s%-18s%-12s%-14s%-14s%s\n", $peer['user'], $peer['ip'], $entry['latest'] > 0 ? 'yes' : 'no', $entry['rx'], $entry['tx'], $endpoint);
}
