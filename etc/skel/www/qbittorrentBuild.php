<?php
/** Account-only build discovery; mirrored in the operator tree for trusted privilege drops. */
require_once __DIR__.'/qbittorrentAccountProcess.php';
require_once __DIR__.'/fileReadVerified.php';
require_once __DIR__.'/qbittorrentBuildProbe.php';

/** Accept only a numeric release with the curated labels; an Enhanced Edition build reads as its upstream release. */
function pmssQbittorrentFrontendBuildNumber(?string $value): ?string
{
    return is_string($value) && preg_match('/\A([0-9]+\.[0-9]+(?:\.[0-9]+)?)(?:\.[0-9]+-ee)?(?:-lt(?:1\.2|2\.[01]))?(?:-not-for-private-trackers)?\z/', trim($value), $m) === 1
        ? $m[1] : null;
}

/** Prefer the last observed release; Track 1 labels suffice for older store selections. */
function pmssQbittorrentFrontendLastBuild(string $home): ?string
{
    foreach (['lastBuild', 'lastRun'] as $name) {
        $path = $home.'/.config/pmss/torrentClients/qbittorrent.'.$name;
        $value = \PMSS\Customer\pmssReadRegularFileContentsVerified($path, null, 128);
        $number = pmssQbittorrentFrontendBuildNumber(is_string($value) ? $value : null);
        if ($number !== null) return $number;
    }
    return null;
}

/** Resolve the executable that will start, or the executable already running for this UID. */
function pmssQbittorrentFrontendBuildBinary(string $home, array $ops = []): ?string
{
    $uid = @fileowner($home);
    $pids = $ops['accountPids'] ?? static function () use ($uid): array {
        return pmssQbittorrentFrontendAccountPids('/proc', is_int($uid) ? $uid : null);
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
function pmssQbittorrentFrontendBuildVersion(string $home, ?string $binary = null, array $ops = []): ?string
{
    $binary = $binary ?? pmssQbittorrentFrontendBuildBinary($home, $ops);
    if ($binary === 'qbittorrent-nox') $binary = pmssQbittorrentFrontendBuildBinary($home, $ops);
    $real = is_string($binary) ? realpath($binary) : false;
    if (!is_string($real)) return null;
    $store = rtrim($ops['store'] ?? '/home/.pmss/clients', '/').'/qbittorrent/';
    if (strpos($real, $store) === 0) {
        $label = explode('/', substr($real, strlen($store)), 2)[0];
        $data = json_decode((string) @file_get_contents($ops['projection'] ?? '/var/lib/pmss/public/torrentClients.json'), true);
        foreach ((array) ($data['versions'] ?? []) as $entry) {
            if (($entry['client'] ?? null) === 'qbittorrent' && ($entry['version'] ?? null) === $label)
                return pmssQbittorrentFrontendBuildNumber($entry['version']);
        }
        return null;
    }
    $dir = $home.'/.config/pmss/torrentClients';
    $path = $dir.'/qbittorrent.version-'.hash('sha256', $real.':'.(string) @filemtime($real));
    if (($raw = \PMSS\Customer\pmssReadRegularFileContentsVerified($path, null, 128)) !== null) {
        $cached = pmssQbittorrentFrontendBuildNumber($raw);
        if ($cached !== null) return $cached;
    }
    // Root may read the account cache, but must never execute an account-controlled binary.
    if (!isset($ops['probe']) && (posix_geteuid() === 0 || @fileowner($home) !== posix_geteuid())) return null;
    $probe = $ops['probe'] ?? 'pmssQbittorrentFrontendBuildProbe';
    $output = $probe($real);
    $number = is_string($output) && preg_match('/\bqBittorrent\s+v?([0-9]+\.[0-9]+(?:\.[0-9]+)?)/i', $output, $m) === 1 ? $m[1] : null;
    if ($number !== null && (is_dir($dir) || @mkdir($dir, 0700, true)) && !is_link($path)) {
        // Same-UID cache, no configuration values; use the existing account writer when loaded.
        $write = $ops['write'] ?? 'pmssConfigureFileWrite';
        if (is_callable($write)) $write($path, $number."\n", 0600);
    }
    return $number;
}

/** Record only an observed, surviving launch, never the selection we merely attempted. */
function pmssQbittorrentFrontendBuildRecord(string $home, ?string $version): void
{
    $number = pmssQbittorrentFrontendBuildNumber($version);
    if ($number === null) return;
    $path = $home.'/.config/pmss/torrentClients/qbittorrent.lastBuild';
    if (is_link($path) || file_exists($path) && !is_file($path)) return;
    if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0700, true)) return;
    pmssConfigureFileWrite($path, $number."\n");
}

/**
 * The disk backend the selected build starts with: the customer-side twin of scripts/lib/user/qbittorrentVersion.php
 * (#983). '' drops the key, because lt1.2 builds do not know it; anything not in the store follows the system rule.
 */
function pmssQbittorrentFrontendDiskIoType(string $home, array $ops = []): string
{
    $target = @readlink($home.'/bin/qbittorrent/default');
    $store = rtrim($ops['store'] ?? '/home/.pmss/clients', '/').'/qbittorrent/';
    $family = '';
    if (is_string($target) && strpos($target, $store) === 0) {
        $version = substr($target, strlen($store));
        $data = json_decode((string) @file_get_contents($ops['projection'] ?? '/var/lib/pmss/public/torrentClients.json'), true);
        foreach ((array) ($data['versions'] ?? []) as $entry) {
            if (($entry['client'] ?? null) === 'qbittorrent' && ($entry['version'] ?? null) === $version
                && (is_string($entry['compatFamily'] ?? null) || is_int($entry['compatFamily'] ?? null)))
                $family = (string) $entry['compatFamily'];
        }
    }
    if ($family === 'lt1.2') return '';
    return preg_match('/\A[0-9]+(?:\.[0-9]+){0,2}\z/', $family) === 1 && version_compare($family, '5.0.1', '>=')
        ? 'SimplePreadPwrite' : 'Posix';
}

/** Set, or for '' remove, Session\DiskIOType in [BitTorrent] without touching any other byte; unchanged on any error. */
function pmssQbittorrentFrontendDiskIoTypeApply(string $config, string $type): string
{
    if ($type === '') {
        $updated = preg_replace('/^Session\\\\DiskIOType=.*\r?\n?/m', '', $config);
    } else {
        $values = pmssQbittorrentFrontendConfigValues($config);
        if (($values['BitTorrent']['Session\\DiskIOType'] ?? null) === $type) return $config;
        $updated = pmssQbittorrentFrontendConfigSet($config, 'BitTorrent', 'Session\\DiskIOType', $type, true);
    }
    return is_string($updated) ? $updated : $config;
}
