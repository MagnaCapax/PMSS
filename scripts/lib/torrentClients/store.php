<?php
/** Converge only root-owned versioned artifacts. */

require_once __DIR__.'/storePaths.php';
require_once __DIR__.'/storeInstall.php';
require_once __DIR__.'/transmissionWeb.php';

/** Converge non-system entries; never prune a previously installed version. */
function pmssTorrentClientsStoreConverge(array $catalog, array $ops = []): array
{
    $root = $ops['root'] ?? '/home/.pmss/clients';
    $free = $ops['free'] ?? 'pmssTorrentClientsStoreFreeSpace';
    $size = $ops['size'] ?? 'pmssTorrentClientsStoreSetSize';
    $log = $ops['log'] ?? 'logmsg';
    $install = $ops['install'] ?? 'pmssTorrentClientsStoreInstall';
    // The update's /home tenant sweep resets dot-dirs to 0700 before this runs;
    // restore traverse every time so installed versions stay reachable.
    if (is_dir($root) && !is_link($root) && pmssTorrentClientsStorePathChainSafe($root)) {
        pmssTorrentClientsStoreDirectory(dirname($root));
        pmssTorrentClientsStoreDirectory($root);
    }
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
        if (!pmssTorrentClientsBinaryAvailable($binary, $client, $entry['kind'])
            || ($entry['kind'] === 'static' && hash_file('sha256', $binary) !== strtolower($entry['sha256'] ?? ''))) {
            $freeBytes = $free();
            $setBytes = $size($root);
            // Subtract the reserve so a large store cannot promote the sum to float.
            if (!is_int($freeBytes) || !is_int($setBytes)
                || $freeBytes < 0 || $setBytes < 0
                || $freeBytes - 3 * 1024 * 1024 * 1024 <= $setBytes) {
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
        }
        $results[$id] = 'installed';
        // Existing verified daemons also need their separately pinned web client.
        if ($client === 'transmission' && array_key_exists('web', $entry)) {
            pmssTorrentClientsTransmissionWebConverge($entry, $root, ($ops['web'] ?? []) + ['log' => $log]);
        }
        if (empty($entry['isDefault'])) continue;
        $alias = $root.'/'.$client.'/default';
        $temp = $alias.'.tmp-'.getmypid();
        if (!is_link($alias) && file_exists($alias)) continue;
        if (@symlink($version, $temp) && !@rename($temp, $alias)) @unlink($temp);
    }
    return $results;
}
