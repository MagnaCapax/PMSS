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
