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

/** Publish available store versions and create a default only when absent. */
function pmssTorrentClientsUserLinksConverge(string $user, string $home, array $ops = []): bool
{
    if (!pmssTorrentClientsMarkerEnabled()) return true;
    $homeRoot = $ops['homeRoot'] ?? '/home';
    if (!pmssValidateUsername($user) || $home !== rtrim($homeRoot, '/').'/'.$user) return false;
    $root = $ops['root'] ?? '/home/.pmss/clients';
    $ok = true;
    foreach (['qbittorrent', 'rtorrent', 'deluge', 'transmission'] as $client) {
        $storeClient = $root.'/'.$client;
        if (!pmssTorrentClientsStorePathChainSafe($root, $client) || !is_dir($storeClient)) continue;
        $versions = @scandir($storeClient);
        if (!is_array($versions)) continue;
        $bin = $home.'/bin';
        $clientBin = $bin.'/'.$client;
        $command = 'mkdir -p '.escapeshellarg($bin);
        $ok = pmssTorrentClientsUserLinksRun($user, $home, [$bin], $command, $ops) && $ok;
        if (!$ok) continue;
        $command = 'mkdir -p '.escapeshellarg($clientBin);
        $ok = pmssTorrentClientsUserLinksRun($user, $home, [$clientBin], $command, $ops) && $ok;
        if (!$ok) continue;
        foreach ($versions as $version) {
            if ($version === 'default' || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $version) !== 1
                || !pmssTorrentClientsStorePathChainSafe($root, $client, $version)
                || !is_dir($storeClient.'/'.$version)) continue;
            $target = $storeClient.'/'.$version;
            $link = $home.'/bin/'.$client.'/'.$version;
            $command = 'mkdir -p '.escapeshellarg(dirname($link))
                .' && if [ ! -e '.escapeshellarg($link).' ] && [ ! -L '.escapeshellarg($link).' ]; then '
                .'ln -s '.escapeshellarg($target).' '.escapeshellarg($link).'; fi';
            // Check the real parent; an existing link is an expected no-op on rerun.
            $ok = pmssTorrentClientsUserLinksRun($user, $home, [$clientBin], $command, $ops) && $ok;
        }
        $default = @readlink($storeClient.'/default');
        if (!is_string($default) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $default) !== 1
            || !pmssTorrentClientsStorePathChainSafe($root, $client, $default)
            || !is_dir($storeClient.'/'.$default)) continue;
        $link = $home.'/bin/'.$client.'/default';
        $command = 'mkdir -p '.escapeshellarg(dirname($link))
            .' && if [ ! -e '.escapeshellarg($link).' ] && [ ! -L '.escapeshellarg($link).' ]; then '
            .'ln -s '.escapeshellarg($storeClient.'/'.$default).' '.escapeshellarg($link).'; fi';
        $ok = pmssTorrentClientsUserLinksRun($user, $home, [$clientBin], $command, $ops) && $ok;
    }
    return $ok;
}
