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

/** Availability checks presence only; the installer separately verifies static pins. */
function pmssTorrentClientsBinaryAvailable($binary, string $client, $kind): bool
{
    return is_string($binary) && is_file($binary) && !is_link($binary)
        && ($client !== 'deluge' || $kind !== 'venv'
            || (is_file(dirname($binary).'/deluge-web') && !is_link(dirname($binary).'/deluge-web')));
}

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

/** Measure the existing root-owned set before reserving headroom for a new copy. */
function pmssTorrentClientsStoreSetSize(string $root): ?int
{
    if ($root === '' || pmssFilesystemPathHasNulByte($root)) return null;
    if (!is_dir($root)) return 0;
    if (is_link($root)) return null;
    $output = [];
    $rc = 1;
    @exec('du -sb -- '.escapeshellarg($root).' 2>/dev/null', $output, $rc);
    if ($rc !== 0 || !isset($output[0]) || !preg_match('/\A([0-9]+)\s/', $output[0], $match)) return null;
    return pmssUnsignedDecimalIntParse($match[1]);
}

/** Reject failed or unrepresentable filesystem measurements before integer conversion. */
function pmssTorrentClientsStoreFreeSpace(): ?int
{
    $bytes = @disk_free_space('/home');
    if (is_int($bytes)) return $bytes >= 0 ? $bytes : null;
    // The float at PHP_INT_MAX may already round up beyond the integer range.
    return is_float($bytes) && is_finite($bytes) && $bytes >= 0 && $bytes < PHP_INT_MAX
        ? (int) $bytes : null;
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
