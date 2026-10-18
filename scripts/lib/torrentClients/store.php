<?php
/** Marker-gated callers may converge only root-owned versioned artifacts. */

require_once dirname(__DIR__).'/update/apps/remoteBinary.php';
require_once __DIR__.'/storeArchive.php';
require_once __DIR__.'/storePaths.php';
require_once __DIR__.'/delugeVenv.php';

/** Measure the existing root-owned set before reserving headroom for a new copy. */
function pmssTorrentClientsStoreSetSize(string $root): ?int
{
    if (!is_dir($root)) return 0;
    if (is_link($root)) return null;
    $output = [];
    $rc = 1;
    @exec('du -sb -- '.escapeshellarg($root).' 2>/dev/null', $output, $rc);
    if ($rc !== 0 || !isset($output[0]) || !preg_match('/\A([0-9]+)\s/', $output[0], $match)) return null;
    return (int) $match[1];
}

/** Ensure a root-owned real directory with public traverse permission. */
function pmssTorrentClientsStoreDirectory(string $path): bool
{
    if (is_link($path) || (!is_dir($path) && !@mkdir($path, 0755))) return false;
    $stat = @lstat($path);
    [$uid, $gid] = pmssTorrentClientsStoreOwnerIds();
    return is_array($stat) && ($stat['mode'] & 0170000) === 0040000
        && $stat['uid'] === $uid && $stat['gid'] === $gid && @chmod($path, 0755);
}

/** Converge non-system entries; never prune a previously installed version. */
function pmssTorrentClientsStoreConverge(array $catalog, array $ops = []): array
{
    if (!pmssTorrentClientsMarkerEnabled()) return [];
    $root = $ops['root'] ?? '/home/.pmss/clients';
    $free = $ops['free'] ?? static function (): ?int {
        $bytes = @disk_free_space('/home');
        return is_float($bytes) || is_int($bytes) ? (int) $bytes : null;
    };
    $size = $ops['size'] ?? 'pmssTorrentClientsStoreSetSize';
    $log = $ops['log'] ?? 'logmsg';
    $install = $ops['install'] ?? static function (array $entry, string $binary): bool {
        if ($entry['client'] === 'deluge' && $entry['kind'] === 'venv') {
            return pmssTorrentClientsDelugeVenvInstall($entry, dirname(dirname($binary)));
        }
        if ($entry['kind'] === 'venv') return pmssTorrentClientsStoreVenvInstall($entry, $binary);
        foreach ($entry['url'] as $url) {
            pmssInstallPinnedRemoteBinary(
                $entry['client'].' '.$entry['version'], $url, $entry['sha256'], $binary, true
            );
            if (is_file($binary) && hash_file('sha256', $binary) === strtolower($entry['sha256'])) return true;
        }
        return false;
    };
    $results = [];
    foreach ($catalog as $entry) {
        if (!is_array($entry) || !isset($entry['client'], $entry['version'], $entry['kind'])) continue;
        if (!empty($entry['disabled']) || $entry['kind'] === 'system') continue;
        $client = $entry['client'];
        $version = $entry['version'];
        $id = $client.'/'.$version;
        // Even direct callers must not turn unchecked catalog values into paths.
        if (!is_string($client) || !isset(pmssTorrentClientsBinaryNames()[$client])
            || !pmssTorrentClientsVersionValid($version)) {
            $results[$id] = 'invalid';
            continue;
        }
        $binary = pmssTorrentClientsStoreBinaryPath($root, $client, $version, $entry['kind']);
        if (!pmssTorrentClientsStorePathChainSafe($root, $client, $version)) {
            $log('[WARN] torrent client '.$id.' unsafe store directory');
            $results[$id] = 'failed';
            continue;
        }
        if (is_file($binary) && !is_link($binary)
            && ($client !== 'deluge' || $entry['kind'] !== 'venv'
                || (is_file(dirname($binary).'/deluge-web') && !is_link(dirname($binary).'/deluge-web')))
            && ($entry['kind'] !== 'static'
                || hash_file('sha256', $binary) === strtolower($entry['sha256'] ?? ''))) {
            $results[$id] = 'installed';
        } else {
            $freeBytes = $free();
            $setBytes = $size($root);
            if (!is_int($freeBytes) || !is_int($setBytes)
                || $freeBytes <= 3 * 1024 * 1024 * 1024 + $setBytes) {
                $log('[SKIP] torrent client '.$id.' insufficient /home free space or unreadable set size');
                $results[$id] = 'skipped-space';
                continue;
            }
            if (!pmssTorrentClientsStoreDirectory(dirname($root))
                || !pmssTorrentClientsStoreDirectory($root)
                || !pmssTorrentClientsStoreDirectory($root.'/'.$client)
                || ($entry['kind'] !== 'venv' && !pmssTorrentClientsStoreDirectory($root.'/'.$id))
                || !$install($entry, $binary)) {
                $log('[WARN] torrent client '.$id.' install failed');
                $results[$id] = 'failed';
                continue;
            }
            @chmod($binary, 0755);
            $results[$id] = 'installed';
        }
        if (!empty($entry['isDefault'])) {
            $alias = $root.'/'.$client.'/default';
            $temp = $alias.'.tmp-'.getmypid();
            if (!is_link($alias) && file_exists($alias)) continue;
            if (@symlink($version, $temp)) {
                if (!@rename($temp, $alias)) @unlink($temp);
            }
        }
    }
    return $results;
}
