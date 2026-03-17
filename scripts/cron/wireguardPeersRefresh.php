#!/usr/bin/env php
<?php
/**
 * Periodic WireGuard peer refresh based on per-user ~/.wireguard-public-key files.
 *
 * - Reads validated public keys from /home/<user>/.wireguard-public-key
 * - Regenerates wg0.conf (interface + peers) only when content changes
 * - Restarts or starts wg-quick@wg0 when an update is applied
 *
 * Safe to run frequently; work is proportional to the number of keys.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

define('PMSS_WIREGUARD_NO_ENTRYPOINT', true);

require_once __DIR__.'/../lib/wireguard.php';

if (!wgSupports()) {
    // WireGuard tooling missing; nothing to do.
    exit(0);
}

$configDir = wgConfigDir();
if (!is_dir($configDir)) {
    // Config directory not provisioned yet.
    exit(0);
}

// Ensure server keys exist.
[$privKey, $pubKey] = wgEnsureKeys($configDir);
if ($privKey === '' || $pubKey === '') {
    wgLog('wireguardPeersRefresh: server keys unavailable; skipping refresh');
    exit(0);
}

$listenPort = 51820;
$configPath = $configDir.'/wg0.conf';
$current    = is_file($configPath) ? (string) file_get_contents($configPath) : '';

$newConfig = wireguardBuildConfig($privKey, $listenPort);

if ($newConfig === $current) {
    // No change in peers or base config; avoid unnecessary restarts.
    exit(0);
}

file_put_contents($configPath, $newConfig);
chmod($configPath, 0640);
wgLog('wireguardPeersRefresh: updated wg0.conf with current peer set');

// Apply config only when systemd is available.
if (is_dir('/run/systemd/system')) {
    exec('systemctl is-active --quiet wg-quick@wg0', $_, $rc);
    $cmd = $rc === 0 ? 'systemctl restart wg-quick@wg0' : 'systemctl start wg-quick@wg0';
    exec($cmd, $_, $rc2);
    if ($rc2 !== 0) {
        wgLog('wireguardPeersRefresh: '.$cmd.' failed (rc='.$rc2.')');
    }
}
