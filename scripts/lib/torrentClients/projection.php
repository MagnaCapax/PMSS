<?php
/** Publish the host-only client availability view for customer PHP. */

require_once dirname(__DIR__).'/systemStats/hostPressure.php';
require_once __DIR__.'/storePaths.php';

/** Atomically write root-owned 0644 JSON without tenant names or paths. */
function pmssTorrentClientsProjectionWrite(
    array $catalog,
    string $publicPath = '/var/lib/pmss/public/torrentClients.json'
): bool {
    if (!pmssTorrentClientsMarkerEnabled()) return false;
    $root = getenv('PMSS_TORRENT_CLIENTS_STORE_ROOT') ?: '/home/.pmss/clients';
    $versions = [];
    $binaryNames = [
        'qbittorrent' => 'qbittorrent-nox', 'rtorrent' => 'rtorrent',
        'deluge' => 'deluged', 'transmission' => 'transmission-daemon',
    ];
    foreach ($catalog as $entry) {
        if (!is_array($entry) || !isset($binaryNames[$entry['client'] ?? ''], $entry['version'], $entry['kind'])) continue;
        $client = $entry['client'];
        $version = $entry['version'];
        $binary = $entry['kind'] === 'system' ? ($entry['binary'] ?? '')
            : $root.'/'.$client.'/'.$version.'/'.$binaryNames[$client];
        if (!is_string($binary) || !is_file($binary) || is_link($binary)) continue;
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
    $ownerUid = defined('PMSS_TEST_MODE') && PMSS_TEST_MODE ? posix_geteuid() : 0;
    $ownerGid = defined('PMSS_TEST_MODE') && PMSS_TEST_MODE ? posix_getegid() : 0;
    if (!pmssCheckDirectoriesEnsurePublicDirectory(dirname($publicPath), static function (string $message): void {
        fwrite(STDERR, $message.PHP_EOL);
    }, $ownerUid, $ownerGid)) return false;
    $json = json_encode(['versions' => $versions], JSON_UNESCAPED_SLASHES);
    return is_string($json) && pmssAtomicWriteFile($publicPath, $json.PHP_EOL, 0644);
}
