<?php
/** Publish the host-only client availability view for customer PHP. */

require_once dirname(__DIR__).'/systemStats/hostPressure.php';
require_once __DIR__.'/storePaths.php';

/** Read the published versions through one bounded regular-file check. */
function pmssTorrentClientsProjectionVersionsRead(string $path): array {
    $raw = pmssReadRegularFileContentsVerified($path, null, 65536); $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) && is_array($data['versions'] ?? null) ? $data['versions'] : [];
}

/** Atomically write root-owned 0644 JSON without tenant names or paths. */
function pmssTorrentClientsProjectionWrite(
    array $catalog,
    string $publicPath = '/var/lib/pmss/public/torrentClients.json'
): bool {
    if (!pmssTorrentClientsMarkerEnabled()) return false;
    $root = getenv('PMSS_TORRENT_CLIENTS_STORE_ROOT') ?: '/home/.pmss/clients';
    $versions = [];
    foreach ($catalog as $entry) {
        if (!is_array($entry) || !isset(pmssTorrentClientsBinaryNames()[$entry['client'] ?? ''], $entry['version'], $entry['kind'])) continue;
        $client = $entry['client'];
        $version = $entry['version'];
        $binary = $entry['kind'] === 'system' ? ($entry['binary'] ?? '')
            : pmssTorrentClientsStoreBinaryPath($root, $client, $version, $entry['kind']);
        if (!is_string($binary) || !is_file($binary) || is_link($binary)) continue;
        if ($client === 'deluge' && $entry['kind'] === 'venv'
            && (!is_file(dirname($binary).'/deluge-web') || is_link(dirname($binary).'/deluge-web'))) continue;
        $published = [
            'client' => $client,
            'version' => $version,
            'kind' => $entry['kind'],
            'isDefault' => (bool) ($entry['isDefault'] ?? false),
            'compatFamily' => $entry['compatFamily'] ?? '',
            'badges' => $entry['cve'] ?? [],
        ];
        if ($client === 'transmission') {
            if (($entry['rpcUnixSocket'] ?? null) !== true || $entry['kind'] !== 'static') continue;
            $published['rpcUnixSocket'] = true;
        }
        $versions[] = $published;
    }
    if (!pmssPathSegmentsAreSafe($publicPath, false, true, true, false)) return false;
    [$ownerUid, $ownerGid] = pmssTorrentClientsStoreOwnerIds();
    if (!pmssCheckDirectoriesEnsurePublicDirectory(dirname($publicPath), static function (string $message): void {
        fwrite(STDERR, $message.PHP_EOL);
    }, $ownerUid, $ownerGid)) return false;
    $json = json_encode(['versions' => $versions], JSON_UNESCAPED_SLASHES);
    return is_string($json) && pmssAtomicWriteFile($publicPath, $json.PHP_EOL, 0644);
}
