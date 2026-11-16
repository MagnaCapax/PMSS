<?php
/** WireGuard watchdog module/peer observations and peer reconciliation.
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/** Parse lsmod output without shell pipelines or regexing command strings. */
function pmssWireguardLsmodOutputHasModule(string $output): bool
{
    foreach (pmssConfigRows(preg_split('/\R/', $output) ?: [], 1, []) as $columns) {
        if ($columns[0] === 'wireguard') {
            return true;
        }
    }

    return false;
}

/**
 * Read the peer keys currently loaded in the kernel interface.
 *
 * @return array{status:string,keys:array<int,string>,rc:int}
 */
function pmssWireguardRunningPeerPublicKeys(): array
{
    $result = pmssWireguardCommandCapture('wg', ['show', 'wg0', 'peers']);
    if ($result['rc'] !== 0) {
        return ['status' => 'unreadable', 'keys' => [], 'rc' => $result['rc']];
    }

    $keys = [];
    $output = preg_split('/\R/', trim($result['stdout'])) ?: [];
    foreach (pmssConfigLines($output, []) as $key) {
        if (wgValidatePublicKey($key)) {
            $keys[$key] = true;
        }
    }
    $keys = array_keys($keys);
    sort($keys, SORT_STRING);

    return ['status' => 'ok', 'keys' => $keys, 'rc' => 0];
}

/** @return array<int,string> */
function pmssWireguardMissingPeerPublicKeys(array $configuredKeys, array $runningKeys): array
{
    $running = array_fill_keys($runningKeys, true);
    return array_values(array_filter($configuredKeys, static function ($key) use ($running): bool {
        return !isset($running[$key]);
    }));
}

/** Verify that configured peers are loaded into the running wg0 interface. */
function pmssWireguardReconcileConfiguredPeers(string $configPath, array $peerUsers, string $logPrefix, bool $debug): void
{
    $configured = pmssWireguardPeerPublicKeysFromConfig($configPath);
    if ($configured['status'] !== 'ok' || $configured['keys'] === []) {
        if ($debug) {
            echo $logPrefix . "wg0 config has no peers to reconcile\n";
        }
        return;
    }

    $running = pmssWireguardRunningPeerPublicKeys();
    if ($running['status'] !== 'ok') {
        echo $logPrefix . "failed to inspect running wg0 peers (rc={$running['rc']})\n";
        return;
    }

    $missingCount = count(pmssWireguardMissingPeerPublicKeys($configured['keys'], $running['keys']));
    if ($missingCount === 0) {
        if ($debug) {
            echo $logPrefix . "wg0 runtime peers match config\n";
        }
        return;
    }

    echo $logPrefix . "wg0 missing ".$missingCount." configured peer(s), attempting syncconf\n";
    $sync = pmssWireguardSyncconfFromConfig();
    if ($sync['rc'] === 0) {
        echo $logPrefix . "wg0 peer set reconciled with syncconf\n";
        pmssWireguardLogUsers($peerUsers, 'wireguard: wg0 peer set reconciled with syncconf');
        return;
    }

    echo $logPrefix . "wg peer reconcile failed during {$sync['stage']} (rc={$sync['rc']}), attempting restart\n";
    pmssWireguardRestartWgQuick($peerUsers, $logPrefix);
}
