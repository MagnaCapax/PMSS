<?php
/** Pure validation of the operator-owned torrent client catalog. */

require_once dirname(__DIR__).'/runtime/filesystem.php';

/** Reject the entire document if any entry is malformed or ambiguous. */
function pmssTorrentClientsCatalogLoad(string $path): array
{
    $raw = pmssReadRegularFileContents($path);
    if (!is_string($raw)) return [];
    $entries = json_decode($raw, true);
    if (!is_array($entries) || $entries === []) return [];
    $seen = [];
    $defaults = [];
    foreach ($entries as $index => $entry) {
        if (!is_int($index) || $index < 0 || !is_array($entry)) return [];
        $client = $entry['client'] ?? null;
        $version = $entry['version'] ?? null;
        $kind = $entry['kind'] ?? null;
        if (!is_string($client) || !in_array($client, ['qbittorrent', 'rtorrent', 'deluge', 'transmission'], true)
            || !is_string($version) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $version) !== 1
            || !in_array($kind, ['system', 'static', 'venv'], true)) return [];
        // Transmission entries describe only builds with the managed socket RPC mode.
        if ($client === 'transmission' && ($kind !== 'static' || ($entry['rpcUnixSocket'] ?? null) !== true)) return [];
        if ($client !== 'transmission' && array_key_exists('rpcUnixSocket', $entry)) return [];
        $id = $client.'|'.$version;
        if (isset($seen[$id])) return [];
        $seen[$id] = true;
        if (!is_bool($entry['disabled'] ?? null) || !is_bool($entry['isDefault'] ?? null)
            || !is_array($entry['cve'] ?? null) || array_values($entry['cve']) !== $entry['cve']
            || !(is_string($entry['compatFamily'] ?? null) || is_int($entry['compatFamily'] ?? null))
            || (string) $entry['compatFamily'] === '') return [];
        foreach ($entry['cve'] as $cve) {
            if (!is_string($cve) || preg_match('/\ACVE-[0-9]{4}-[0-9]{4,}\z/', $cve) !== 1) return [];
        }
        if ($entry['isDefault']) {
            if (isset($defaults[$client]) || $entry['disabled']) return [];
            $defaults[$client] = true;
        }
        if ($kind === 'system') {
            $systemBinaries = [
                'qbittorrent' => '/usr/bin/qbittorrent-nox', 'rtorrent' => '/usr/local/bin/rtorrent',
                'deluge' => '/usr/bin/deluged', 'transmission' => '/usr/bin/transmission-daemon',
            ];
            if (!isset($entry['binary']) || !is_string($entry['binary'])
                || $entry['binary'] !== $systemBinaries[$client]
                || ($entry['url'] ?? null) !== [] || ($entry['sha256'] ?? null) !== '') return [];
            continue;
        }
        // A Deluge store version is assembled only from locally mirrored, pinned wheels.
        if ($client === 'deluge') {
            if ($kind !== 'venv' || ($entry['url'] ?? null) !== [] || ($entry['sha256'] ?? null) !== ''
                || !is_array($entry['wheels'] ?? null) || $entry['wheels'] === []
                || array_values($entry['wheels']) !== $entry['wheels']
                || !in_array($entry['compatWindow'] ?? '', ['', 'bullseye-cp37-310'], true)) return [];
            $wheelNames = [];
            foreach ($entry['wheels'] as $wheel) {
                if (!is_array($wheel) || !is_string($wheel['file'] ?? null)
                    || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\.whl\z/', $wheel['file']) !== 1
                    || !is_string($wheel['sha256'] ?? null)
                    || preg_match('/\A[a-fA-F0-9]{64}\z/', $wheel['sha256']) !== 1
                    || isset($wheelNames[$wheel['file']])) return [];
                $wheelNames[$wheel['file']] = true;
            }
            continue;
        }
        if (!is_array($entry['url'] ?? null) || $entry['url'] === []
            || array_values($entry['url']) !== $entry['url']
            || !is_string($entry['sha256'] ?? null)
            || preg_match('/\A[a-fA-F0-9]{64}\z/', $entry['sha256']) !== 1) return [];
        foreach ($entry['url'] as $url) {
            if (!is_string($url) || strpos($url, 'https://') !== 0
                || filter_var($url, FILTER_VALIDATE_URL) === false) return [];
        }
    }
    return $entries;
}
