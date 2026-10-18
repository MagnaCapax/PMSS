<?php
/** Keep root scans inside real, operator-owned store directories. */

/** The development suite exercises writers only inside its temporary tree. */
function pmssTorrentClientsMarkerEnabled(): bool
{
    if (defined('PMSS_TEST_MODE') && PMSS_TEST_MODE) {
        $fixture = getenv('PMSS_TORRENT_CLIENTS_TEST_MARKER');
        return $fixture === false ? true : is_file($fixture);
    }
    return is_file('/etc/seedbox/config/torrentClients.enabled');
}

/** Missing components may be created later; existing ones must be trusted. */
function pmssTorrentClientsStorePathChainSafe(string $root, string $client = '', string $version = ''): bool
{
    $paths = [dirname($root), $root];
    if ($client !== '') $paths[] = $root.'/'.$client;
    if ($version !== '') $paths[] = $root.'/'.$client.'/'.$version;
    $uid = defined('PMSS_TEST_MODE') && PMSS_TEST_MODE ? posix_geteuid() : 0;
    $gid = defined('PMSS_TEST_MODE') && PMSS_TEST_MODE ? posix_getegid() : 0;
    foreach ($paths as $path) {
        clearstatcache(true, $path);
        if (($stat = @lstat($path)) === false) continue;
        // Ownership alone does not prevent a tenant from replacing store entries.
        if (($stat['mode'] & 0170000) !== 0040000 || $stat['uid'] !== $uid || $stat['gid'] !== $gid
            || ($stat['mode'] & 0022) !== 0) return false;
    }
    return true;
}
