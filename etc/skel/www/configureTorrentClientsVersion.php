<?php
/** Customer-side projection reader and conservative version-family comparison. */

/** Read a bounded projection, retaining null for unavailable or malformed files. */
function pmssConfigureTorrentClientProjectionRead($path)
{
    if (!is_file($path) || is_link($path) || !is_readable($path) || @filesize($path) > 65536) return null;
    $raw = @file_get_contents($path, false, null, 0, 65537);
    if (!is_string($raw) || strlen($raw) > 65536) return null;
    $data = json_decode($raw, true);
    return is_array($data) && is_array($data['versions'] ?? null) ? $data : null;
}

/** Distinguish an unavailable projection from a valid projection with no installed entries. */
function pmssConfigureTorrentClientVersionsAvailable($path = '/var/lib/pmss/public/torrentClients.json')
{
    return pmssConfigureTorrentClientProjectionRead($path) !== null;
}

/** Older projections and malformed counts default to zero for each known client. */
function pmssConfigureTorrentClientNotInstalledRead($path = '/var/lib/pmss/public/torrentClients.json')
{
    $counts = array('qbittorrent' => 0, 'rtorrent' => 0, 'deluge' => 0, 'transmission' => 0);
    $data = pmssConfigureTorrentClientProjectionRead($path);
    $published = $data['notInstalled'] ?? null;
    if (is_array($published)) foreach ($counts as $client => $zero) {
        $count = $published[$client] ?? null;
        if (is_int($count) && $count >= 0) $counts[$client] = $count;
    }
    return $counts;
}

/** Read only installed versions from the root-published projection. */
function pmssConfigureTorrentClientVersionsRead($path = '/var/lib/pmss/public/torrentClients.json', $client = 'qbittorrent')
{
    if (!is_file($path) || is_link($path) || @filesize($path) > 65536) return array();
    $data = json_decode((string) @file_get_contents($path), true);
    $versions = array();
    foreach ((array) ($data['versions'] ?? array()) as $entry) {
        if (!is_array($entry) || ($entry['client'] ?? '') !== $client
            || !is_string($entry['version'] ?? null)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $entry['version']) !== 1
            || !in_array($entry['kind'] ?? '', array('system', 'static', 'venv'), true)) continue;
        $versions[$entry['version']] = $entry;
    }
    return $versions;
}

/** Offer installed client versions from the root-published projection. */
function pmssConfigureTorrentClientVersionsOffered($client = 'qbittorrent',
    $path = '/var/lib/pmss/public/torrentClients.json')
{
    return pmssConfigureTorrentClientVersionsRead($path, $client);
}

/** Name the libtorrent generation of a curated qBittorrent build in words. */
function pmssConfigureTorrentClientVersionLabel($version): string
{
    $version = (string) $version;
    return preg_match('/\A([0-9]+\.[0-9]+(?:\.[0-9]+)?)-lt(1\.2|2\.0)\z/', $version, $m) === 1
        ? $m[1].' (libtorrent '.$m[2].')' : $version;
}

/** Numeric releases, including curated libtorrent suffixes, prove major downgrades. */
function pmssConfigureTorrentClientMajorDowngrade($previous, $requested): bool
{
    if (!is_string($previous) || !is_string($requested)
        || preg_match('/\A([0-9]+)\.[0-9]+(?:\.[0-9]+)?(?:-lt(?:1\.2|2\.0))?\z/', $previous, $old) !== 1
        || preg_match('/\A([0-9]+)\.[0-9]+(?:\.[0-9]+)?(?:-lt(?:1\.2|2\.0))?\z/', $requested, $new) !== 1) return false;
    // Compare decimal strings without integer overflow from long catalog values.
    $oldMajor = ltrim($old[1], '0');
    $newMajor = ltrim($new[1], '0');
    return strlen($newMajor) < strlen($oldMajor)
        || strlen($newMajor) === strlen($oldMajor) && strcmp($newMajor, $oldMajor) < 0;
}
