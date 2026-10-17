<?php
/** Customer-side resolver; it has no dependency on operator-only /scripts. */

/** Return a canonical executable only for a direct, allowed directory target. */
function pmssTorrentClientResolve(string $home, string $client): ?string
{
    $names = [
        'qbittorrent' => 'qbittorrent-nox', 'rtorrent' => 'rtorrent',
        'deluge' => 'deluged', 'transmission' => 'transmission-daemon',
    ];
    if (!isset($names[$client]) || $home === '' || $home[0] !== '/'
        || strpos($home, "\0") !== false || strpos($home, '/..') !== false) return null;
    $base = rtrim($home, '/').'/bin/'.$client;
    $target = @readlink($base.'/default');
    if (!is_string($target) || $target === '') return null;
    $storePrefix = '/home/.pmss/clients/'.$client.'/';
    $ownPrefix = $base.'/';
    if ($target[0] !== '/') $target = $ownPrefix.$target;
    if (strpos($target, $storePrefix) === 0) {
        $version = substr($target, strlen($storePrefix));
        $store = true;
    } elseif (strpos($target, $ownPrefix) === 0) {
        $version = substr($target, strlen($ownPrefix));
        $store = false;
    } else return null;
    if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $version) !== 1
        || $version === 'default' || !is_dir($target) || is_link($target)) return null;
    if ($store) {
        $projection = '/var/lib/pmss/public/torrentClients.json';
        $stat = @lstat($projection);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000) return null;
        $raw = @file_get_contents($projection);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $listed = false;
        foreach ((array) ($data['versions'] ?? []) as $entry) {
            if (($entry['client'] ?? null) === $client && ($entry['version'] ?? null) === $version
                && ($entry['kind'] ?? null) !== 'system') { $listed = true; break; }
        }
        if (!$listed) return null;
    }
    $binary = $target.'/'.$names[$client];
    return is_file($binary) && !is_link($binary) ? $binary : null;
}

/** Start a selected client as the account while excluding a concurrent switch. */
function pmssTorrentClientResolverLaunch(string $home, string $client, bool $lockHeld = false): bool
{
    if ($client !== 'qbittorrent' || !is_link($home.'/bin/'.$client.'/default')) return false;
    $lock = null;
    if (!$lockHeld) {
        $dir = $home.'/.config/pmss/torrentClients';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
        if (is_link($dir)) return false;
        $lock = @fopen($dir.'/'.$client.'.lock', 'c');
        if (!is_resource($lock)) return false;
        if (!@flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return true; }
    }
    $binary = pmssTorrentClientResolve($home, $client);
    $rc = 1;
    if ($binary !== null) @exec(escapeshellarg($binary).' -d >/dev/null 2>&1', $unused, $rc);
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    return $rc === 0;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $home = getenv('HOME');
    exit(is_string($home) && ($argv[1] ?? '') === 'qbittorrent' && ($argv[2] ?? '') === '--launch'
        && pmssTorrentClientResolverLaunch($home, 'qbittorrent', ($argv[3] ?? '') === '--held') ? 0 : 1);
}
