<?php
/** Customer-side projection reader and conservative version-family comparison. */

/** Read a bounded projection, retaining null for unavailable or malformed files. */
function pmssConfigureTorrentClientProjectionRead($path)
{
    if (!is_string($path) || $path === '' || strpos($path, "\0") !== false) return null;
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
    // Enforce the read bound even when stat fails or the projection grows after it.
    $data = pmssConfigureTorrentClientProjectionRead($path);
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

/** Name the libtorrent generation of a curated qBittorrent build in words; Enhanced Edition keeps its warning. */
function pmssConfigureTorrentClientVersionLabel($version): string
{
    $version = (string) $version;
    if (preg_match('/\A([0-9]+\.[0-9]+(?:\.[0-9]+){0,2})-ee-lt(1\.2|2\.[01])(?:-not-for-private-trackers)?\z/', $version, $m) === 1)
        return $m[1].' Enhanced Edition (libtorrent '.$m[2].'), not for private trackers';
    return preg_match('/\A([0-9]+\.[0-9]+(?:\.[0-9]+)?)-lt(1\.2|2\.[01])\z/', $version, $m) === 1
        ? $m[1].' (libtorrent '.$m[2].')' : $version;
}

/** Numeric releases, including curated libtorrent and Enhanced Edition suffixes, prove major downgrades. */
function pmssConfigureTorrentClientMajorDowngrade($previous, $requested): bool
{
    if (!is_string($previous) || !is_string($requested)
        || preg_match('/\A([0-9]+)\.[0-9]+(?:\.[0-9]+){0,2}(?:-ee)?(?:-lt(?:1\.2|2\.[01]))?(?:-not-for-private-trackers)?\z/', $previous, $old) !== 1
        || preg_match('/\A([0-9]+)\.[0-9]+(?:\.[0-9]+){0,2}(?:-ee)?(?:-lt(?:1\.2|2\.[01]))?(?:-not-for-private-trackers)?\z/', $requested, $new) !== 1) return false;
    // Compare decimal strings without integer overflow from long catalog values.
    $oldMajor = ltrim($old[1], '0');
    $newMajor = ltrim($new[1], '0');
    return strlen($newMajor) < strlen($oldMajor)
        || strlen($newMajor) === strlen($oldMajor) && strcmp($newMajor, $oldMajor) < 0;
}

/** True when this request's last failed filesystem call ran out of quota or disk space (never account stderr). */
function pmssConfigureTorrentClientOutOfSpace()
{
    $error = error_get_last();
    return is_array($error) && preg_match('/quota exceeded|no space left/i', (string) $error['message']) === 1;
}

/** The refusal for a failed pre-switch backup; a full account is told why. Callers clear the last error first. */
function pmssConfigureTorrentClientBackupRefusal($snapshot)
{
    if (pmssConfigureTorrentClientOutOfSpace()) return array('ok' => false, 'status' => 507,
        'message' => 'Your account is out of disk space, so the current configuration could not be backed up. Nothing was changed. Free some space and try again.');
    $detail = is_array($snapshot) && is_string($snapshot['message'] ?? null) ? $snapshot['message'] : 'Backup failed';
    return array('ok' => false, 'status' => 503,
        'message' => 'Could not back up the current configuration: '.rtrim($detail, ". \r\n").'. Nothing was changed.');
}

/** Open one client's switch lock; a full account is told so instead of "unavailable" or "already running". */
function pmssConfigureTorrentClientLockOpen($dir, $client, $label)
{
    $full = array('ok' => false, 'status' => 507,
        'message' => 'Your account is out of disk space, so the switch could not start. Nothing was changed. Free some space and try again.');
    $failed = static function () use ($full) {
        return pmssConfigureTorrentClientOutOfSpace()
            ? $full : array('ok' => false, 'status' => 503, 'message' => 'The switch lock is unavailable.');
    };
    error_clear_last();
    if ((!is_dir($dir) && !@mkdir($dir, 0700, true)) || is_link($dir)) return array(null, $failed());
    error_clear_last();
    $lock = @fopen($dir.'/'.$client.'.lock', 'ce');
    if (!is_resource($lock)) return array(null, $failed());
    if (@flock($lock, LOCK_EX | LOCK_NB)) return array($lock, null);
    fclose($lock);
    return array(null, array('ok' => false, 'status' => 409, 'message' => 'A '.$label.' switch is already running.'));
}
