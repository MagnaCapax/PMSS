<?php
/** Keep root scans inside real, operator-owned store directories. */

/** Canonical daemon binary names for catalog, store, and account links. */
function pmssTorrentClientsBinaryNames(): array
{
    return [
        'qbittorrent' => 'qbittorrent-nox', 'rtorrent' => 'rtorrent',
        'deluge' => 'deluged', 'transmission' => 'transmission-daemon',
    ];
}

/** Root owns the store outside the development fixture tree. */
function pmssTorrentClientsStoreOwnerIds(): array { return defined('PMSS_TEST_MODE') && PMSS_TEST_MODE ? [posix_geteuid(), posix_getegid()] : [0, 0]; }

/** Resolve the same versioned daemon path for installation and publication. */
function pmssTorrentClientsStoreBinaryPath(string $root, string $client, $version, $kind): string { return $root.'/'.$client.'/'.$version.($client === 'deluge' && $kind === 'venv' ? '/bin/' : '/').pmssTorrentClientsBinaryNames()[$client]; }

/** One path-component rule for catalog, store, and account-link versions. */
function pmssTorrentClientsVersionValid($version): bool
{
    return is_string($version) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $version) === 1;
}

/** Missing components may be created later; existing ones must be trusted. */
function pmssTorrentClientsStorePathChainSafe(string $root, string $client = '', string $version = ''): bool
{
    $paths = [dirname($root), $root];
    if ($client !== '') $paths[] = $root.'/'.$client;
    if ($version !== '') $paths[] = $root.'/'.$client.'/'.$version;
    [$uid, $gid] = pmssTorrentClientsStoreOwnerIds();
    foreach ($paths as $path) {
        clearstatcache(true, $path);
        if (($stat = @lstat($path)) === false) continue;
        // Ownership alone does not prevent a tenant from replacing store entries.
        if (($stat['mode'] & 0170000) !== 0040000 || $stat['uid'] !== $uid || $stat['gid'] !== $gid
            || ($stat['mode'] & 0022) !== 0) return false;
    }
    return true;
}
