<?php
/** Stored and legacy rTorrent port discovery followed by reservation retirement.
 * Loaded by terminationCleanup.php; public entry points retain their contracts.
 * @license GPL-3.0-only
 */

/** Keep stored keys and legacy patterns in one ordered catalog. */
function pmssTerminateUserRtorrentPortSpecs(): array
{
    return array(
        'scgi' => array('rtorrentPort', '/^scgi_port\s*=\s*(?:[^:]*:)?(\d+)/i'),
        'dht' => array('rtorrentDhtPort', '/^(?:dht_?port|dht\.port(?:\.set)?)\s*=\s*(\d+)/i'),
        'listen' => array('rtorrentListenPort', '/^port_range\s*=\s*(\d+)(?:\s*-\s*\d+)?/i'),
    );
}

/** @param array<string,int> $ports */
function pmssTerminateUserRtorrentPortRecord(string $username, array &$ports, string $type, string $rawPort): void
{
    $knownType = array_key_exists($type, pmssTerminateUserRtorrentPortSpecs());
    $port = $knownType ? pmssNetworkPortParseDigits($rawPort) : null;
    if ($port === null) {
        pmssUserLifecycleContextLogStatusMessage('terminate', 'cleanup_ports_config_invalid', $username, 'WARN',
            'Invalid rTorrent '.($knownType ? 'port' : 'port type').' in config; skipping port reservation cleanup',
            array('type' => $type, 'raw_port' => $rawPort));
        return;
    }

    $ports[$type] = $port;
}

/** Discover ports in one pass: stored values win, then the first valid legacy match fills gaps. */
function pmssTerminateUserRtorrentPorts(string $username, string $portFile, ?UserConfigStore $store = null): array
{
    $store = $store ?: new UserConfigStore();
    $payload = $store->get($username);
    $ports = array();
    if (is_array($payload)) {
        foreach (pmssTerminateUserRtorrentPortSpecs() as $type => [$key]) {
            if (array_key_exists($key, $payload)) {
                pmssTerminateUserRtorrentPortRecord($username, $ports, $type, (string) $payload[$key]);
            }
        }
    }
    if (!file_exists($portFile)) {
        return $ports;
    }

    $configLines = @file($portFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($configLines)) {
        pmssUserLifecycleContextLogStatusMessage('terminate', 'cleanup_ports_config_read', $username, 'WARN', 'Unable to read rTorrent config; skipping reserved port cleanup', array('path' => $portFile));
        return $ports;
    }

    foreach (pmssConfigLines($configLines) as $line) {
        foreach (pmssTerminateUserRtorrentPortSpecs() as $type => [$key, $pattern]) {
            if (isset($ports[$type]) || preg_match($pattern, $line, $matches) !== 1) {
                continue;
            }
            pmssTerminateUserRtorrentPortRecord($username, $ports, $type, $matches[1]);
            break;
        }
    }

    return $ports;
}

function pmssTerminateUserReleaseRtorrentPortReservations(string $username, string $portFile, bool $dryRun, string $portsBase = '/var/lib/pmss/ports', ?UserConfigStore $store = null): bool
{
    $portsBase = rtrim($portsBase, '/');
    $ports = pmssTerminateUserRtorrentPorts($username, $portFile, $store);
    if (count($ports) === 0 && !file_exists($portFile)) {
        return true;
    }

    $ok = true;
    foreach ($ports as $type => $port) {
        $filePath = $portsBase.'/'.$type.'/'.$port;
        if (!pmssPathExistsOrLink($filePath)) {
            continue;
        }
        $ok = pmssTerminateUserUnlinkPath($username, 'release_rtorrent_'.$type.'_port', $filePath, $dryRun) && $ok;
        $ok = pmssTerminateUserRemoveEmptyDir($username, 'remove_empty_rtorrent_'.$type.'_port_dir', dirname($filePath), $dryRun) && $ok;
    }
    $ok = pmssTerminateUserRemoveEmptyDir($username, 'remove_empty_rtorrent_ports_root', $portsBase, $dryRun) && $ok;
    pmssUserLifecycleContextLog('terminate', 'cleanup_ports', $username, array('status' => $dryRun ? 'SKIP' : ($ok ? 'OK' : 'ERR'), 'ports' => $ports, 'dry_run' => $dryRun));

    return $ok;
}
