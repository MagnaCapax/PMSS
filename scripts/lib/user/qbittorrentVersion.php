<?php
/** Resolve the selected qBittorrent compatibility family from the public view. */

require_once __DIR__.'/../torrentClients/projection.php';

function pmssQbittorrentCompatFamily(string $username): string
{
    $home = pmssUserHomeFilePath($username, 'bin/qbittorrent/default');
    $target = @readlink($home);
    $prefix = '/home/.pmss/clients/qbittorrent/';
    if (!is_string($target) || strpos($target, $prefix) !== 0) return '';
    $version = substr($target, strlen($prefix));
    if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $version) !== 1) return '';
    foreach (pmssTorrentClientsProjectionVersionsRead('/var/lib/pmss/public/torrentClients.json') as $entry) {
        if (($entry['client'] ?? '') === 'qbittorrent' && ($entry['version'] ?? '') === $version
            && (is_string($entry['compatFamily'] ?? null) || is_int($entry['compatFamily'] ?? null)))
            return (string) $entry['compatFamily'];
    }
    return '';
}

/** Older lt1.2 builds do not understand DiskIOType. */
function pmssQbittorrentDiskIoType(string $compatFamily): ?string
{
    if ($compatFamily === 'lt1.2') return null;
    return $compatFamily !== '' && preg_match('/\A[0-9]+(?:\.[0-9]+){0,2}\z/', $compatFamily) === 1
        && version_compare($compatFamily, '5.0.1', '>=') ? 'SimplePreadPwrite' : 'Posix';
}

/** Drop the unsupported managed key when the selected build is lt1.2. */
function pmssQbittorrentDiskIoTypeRemove(string $config): ?string
{
    return preg_replace('/^Session\\\\DiskIOType=.*\r?\n?/m', '', $config);
}
