<?php
/** Account-only build discovery; mirrored in the operator tree for trusted privilege drops. */
require_once __DIR__.'/qbittorrentAccountProcess.php';
require_once __DIR__.'/../runtime/filesystem.php';
require_once __DIR__.'/qbittorrentBuildProbe.php';

/** Accept only a numeric release with the curated labels; an Enhanced Edition build reads as its upstream release. */
function pmssQbittorrentBuildNumber(?string $value): ?string
{
    return is_string($value) && preg_match('/\A([0-9]+\.[0-9]+(?:\.[0-9]+)?)(?:\.[0-9]+-ee)?(?:-lt(?:1\.2|2\.[01]))?(?:-not-for-private-trackers)?\z/', trim($value), $m) === 1
        ? $m[1] : null;
}

/** Prefer the last observed release; Track 1 labels suffice for older store selections. */
function pmssQbittorrentLastBuild(string $home): ?string
{
    foreach (['lastBuild', 'lastRun'] as $name) {
        $path = $home.'/.config/pmss/torrentClients/qbittorrent.'.$name;
        $value = pmssReadRegularFileContentsVerified($path, null, 128);
        $number = pmssQbittorrentBuildNumber(is_string($value) ? $value : null);
        if ($number !== null) return $number;
    }
    return null;
}

/** Resolve the executable that will start, or the executable already running for this UID. */
function pmssQbittorrentBuildBinary(string $home, array $ops = []): ?string
{
    $uid = @fileowner($home);
    $pids = $ops['accountPids'] ?? static function () use ($uid): array {
        return pmssQbittorrentAccountPids('/proc', is_int($uid) ? $uid : null);
    };
    foreach ($pids() as $pid) {
        $exe = @readlink('/proc/'.$pid.'/exe');
        if (is_string($exe)) return preg_replace('/ \(deleted\)$/', '', $exe);
    }
    $link = $home.'/bin/qbittorrent/default';
    if (is_link($link)) return $link.'/qbittorrent-nox';
    foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '/usr/local/bin:/usr/bin') as $dir) {
        if ($dir !== '' && $dir[0] === '/' && is_executable($dir.'/qbittorrent-nox')) return $dir.'/qbittorrent-nox';
    }
    return null;
}

/** Store entries name their release; system/custom builds use a short, account-owned cached probe. */
function pmssQbittorrentBuildVersion(string $home, ?string $binary = null, array $ops = []): ?string
{
    $binary = $binary ?? pmssQbittorrentBuildBinary($home, $ops);
    if ($binary === 'qbittorrent-nox') $binary = pmssQbittorrentBuildBinary($home, $ops);
    $real = is_string($binary) ? realpath($binary) : false;
    if (!is_string($real)) return null;
    $store = rtrim($ops['store'] ?? '/home/.pmss/clients', '/').'/qbittorrent/';
    if (strpos($real, $store) === 0) {
        $label = explode('/', substr($real, strlen($store)), 2)[0];
        $data = json_decode((string) @file_get_contents($ops['projection'] ?? '/var/lib/pmss/public/torrentClients.json'), true);
        foreach ((array) ($data['versions'] ?? []) as $entry) {
            if (($entry['client'] ?? null) === 'qbittorrent' && ($entry['version'] ?? null) === $label)
                return pmssQbittorrentBuildNumber($entry['version']);
        }
        return null;
    }
    $dir = $home.'/.config/pmss/torrentClients';
    $path = $dir.'/qbittorrent.version-'.hash('sha256', $real.':'.(string) @filemtime($real));
    if (($raw = pmssReadRegularFileContentsVerified($path, null, 128)) !== null) {
        $cached = pmssQbittorrentBuildNumber($raw);
        if ($cached !== null) return $cached;
    }
    // Root may read the account cache, but must never execute an account-controlled binary.
    if (!isset($ops['probe']) && (posix_geteuid() === 0 || @fileowner($home) !== posix_geteuid())) return null;
    $probe = $ops['probe'] ?? 'pmssQbittorrentBuildProbe';
    $output = $probe($real);
    $number = is_string($output) && preg_match('/\bqBittorrent\s+v?([0-9]+\.[0-9]+(?:\.[0-9]+)?)/i', $output, $m) === 1 ? $m[1] : null;
    if ($number !== null && (is_dir($dir) || @mkdir($dir, 0700, true)) && !is_link($path)) {
        // Same-UID cache, no configuration values; use the existing account writer when loaded.
        $write = $ops['write'] ?? 'pmssReplaceUserFilePreservingMetadata';
        if (is_callable($write)) $write($path, $number."\n", 0600);
    }
    return $number;
}
