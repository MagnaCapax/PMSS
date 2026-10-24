<?php
/** Account-owned symlink convergence; callers never inspect tenant bin as root. */

require_once dirname(__DIR__).'/lighttpd/accountPath.php';
require_once dirname(__DIR__).'/user/identity.php';
require_once __DIR__.'/storePaths.php';
require_once __DIR__.'/catalog.php';
require_once dirname(__DIR__).'/update/users/rutorrentRpcCompatibility.php';

/** Use the established account boundary, with a hermetic test seam. */
function pmssTorrentClientsUserLinksRun(string $user, string $home, array $paths, string $command, array $ops): bool
{
    if (isset($ops['run'])) return $ops['run']($user, $home, $paths, $command);
    return pmssAccountPathRun($user, $home, $paths, $command);
}

/** Publish store versions and seed the rTorrent store default for accounts that never switched. */
function pmssTorrentClientsUserLinksConverge(string $user, string $home, array $ops = []): bool
{
    $homeRoot = $ops['homeRoot'] ?? '/home';
    if (!pmssValidateUsername($user) || $home !== rtrim($homeRoot, '/').'/'.$user) return false;
    $root = $ops['root'] ?? '/home/.pmss/clients';
    $catalog = pmssTorrentClientsCatalogLoad($ops['catalog'] ?? '/etc/seedbox/config/torrentClients.json');
    // Root hashes only the current skeleton; all tenant reads stay in the account command.
    $customSha1 = @sha1_file($ops['rtorrentCustomSkeleton'] ?? '/etc/skel/.rtorrent.rc.custom');
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
        // Only rTorrent: a Transmission default link means "selected" and would start the daemon.
        foreach ($client === 'rtorrent' ? $catalog : [] as $entry) {
            if ($entry['client'] !== $client || !$entry['isDefault'] || $entry['kind'] === 'system') continue;
            if ($customSha1 === false) continue;
            $version = $entry['version'];
            if (!pmssTorrentClientsStorePathChainSafe($root, $client, $version)
                || !is_dir($storeClient.'/'.$version)) continue;
            $link = $clientBin.'/default';
            $lastRun = $home.'/.config/pmss/torrentClients/'.$client.'.lastRun';
            $custom = escapeshellarg($home.'/.rtorrent.rc.custom');
            $compatible = ' && { { [ ! -e '.$custom.' ] && [ ! -L '.$custom.' ]; }'
                .' || { [ -f '.$custom.' ] && [ ! -L '.$custom.' ]'
                .' && [ "$(sha1sum < '.$custom.')" = '.escapeshellarg($customSha1.'  -').' ]; }; }';
            foreach (['php/settings.php' => PMSS_RUTORRENT_RPC_SETTINGS_MARKER,
                'plugins/httprpc/action.php' => PMSS_RUTORRENT_RPC_HTTPRPC_MARKER,
                'plugins/erasedata/init.php' => PMSS_RUTORRENT_RPC_ERASEDATA_MARKER] as $file => $marker) {
                $path = escapeshellarg($home.'/www/rutorrent/'.$file);
                $compatible .= ' && [ -f '.$path.' ] && [ ! -L '.$path.' ]'
                    .' && grep -F -q -- '.escapeshellarg($marker).' '.$path;
            }
            // Inspect account state only inside the account command; never replace a prior choice.
            $command = 'if [ ! -e '.escapeshellarg($link).' ] && [ ! -L '.escapeshellarg($link).' ]'
                .' && [ ! -e '.escapeshellarg($lastRun).' ] && [ ! -L '.escapeshellarg($lastRun).' ]'
                .$compatible.'; then '
                .'ln -s '.escapeshellarg($storeClient.'/'.$version).' '.escapeshellarg($link).'; fi';
            $ok = pmssTorrentClientsUserLinksRun($user, $home, [$clientBin], $command, $ops) && $ok;
        }
    }
    return $ok;
}
