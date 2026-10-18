<?php
/** Account-owned symlink convergence; callers never inspect tenant bin as root. */

require_once dirname(__DIR__).'/lighttpd/accountPath.php';
require_once dirname(__DIR__).'/user/identity.php';
require_once __DIR__.'/storePaths.php';

/** Use the established account boundary, with a hermetic test seam. */
function pmssTorrentClientsUserLinksRun(string $user, string $home, array $paths, string $command, array $ops): bool
{
    if (isset($ops['run'])) return $ops['run']($user, $home, $paths, $command);
    return pmssAccountPathRun($user, $home, $paths, $command);
}

/** Publish available store versions; only an explicit switch selects a default. */
function pmssTorrentClientsUserLinksConverge(string $user, string $home, array $ops = []): bool
{
    if (!pmssTorrentClientsMarkerEnabled()) return true;
    $homeRoot = $ops['homeRoot'] ?? '/home';
    if (!pmssValidateUsername($user) || $home !== rtrim($homeRoot, '/').'/'.$user) return false;
    $root = $ops['root'] ?? '/home/.pmss/clients';
    $ok = true;
    foreach (array_keys(pmssTorrentClientsBinaryNames()) as $client) {
        $storeClient = $root.'/'.$client;
        if (!pmssTorrentClientsStorePathChainSafe($root, $client) || !is_dir($storeClient)) continue;
        $versions = @scandir($storeClient);
        if (!is_array($versions)) continue;
        $bin = $home.'/bin';
        $clientBin = $bin.'/'.$client;
        foreach ([$bin, $clientBin] as $directory) {
            $command = 'mkdir -p '.escapeshellarg($directory);
            $ok = pmssTorrentClientsUserLinksRun($user, $home, [$directory], $command, $ops) && $ok;
            if (!$ok) break;
        }
        if (!$ok) continue;
        foreach ($versions as $version) {
            if ($version === 'default' || !pmssTorrentClientsVersionValid($version)
                || !pmssTorrentClientsStorePathChainSafe($root, $client, $version)
                || !is_dir($storeClient.'/'.$version)) continue;
            $link = $home.'/bin/'.$client.'/'.$version;
            $command = 'mkdir -p '.escapeshellarg(dirname($link))
                .' && if [ ! -e '.escapeshellarg($link).' ] && [ ! -L '.escapeshellarg($link).' ]; then '
                .'ln -s '.escapeshellarg($storeClient.'/'.$version).' '.escapeshellarg($link).'; fi';
            // Check the real parent; an existing link is an expected no-op on rerun.
            $ok = pmssTorrentClientsUserLinksRun($user, $home, [$clientBin], $command, $ops) && $ok;
        }
    }
    return $ok;
}
