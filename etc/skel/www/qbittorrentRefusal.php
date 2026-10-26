<?php
/** Stopped watchdog recovery from an observed default-port fallback. */
require_once __DIR__.'/qbittorrentStoppedConfig.php';

/** A marker blocks starts, not managed repairs; release only after the next build has port and hash. */
function pmssQbittorrentFrontendRefusalReady(string $home, array $ops = []): bool
{
    $marker = $home.'/.config/pmss/torrentClients/qbittorrent.refused';
    if (!file_exists($marker) && !is_link($marker)) return true;
    $pids = $ops['accountPids'] ?? 'pmssQbittorrentFrontendAccountPids';
    if ($pids() !== []) return false;
    $read = $ops['read'] ?? static function (string $path) { return @file_get_contents($path); };
    $config = $read($home.'/.config/qBittorrent/qBittorrent.conf');
    if (!is_string($config)) return false;
    $build = $ops['build'] ?? pmssQbittorrentFrontendBuildVersion($home, null, $ops);
    $port = trim((string) $read($home.'/.qbittorrentPort'));
    $configured = trim((string) pmssQbittorrentFrontendWebUiValue($config, 'Port', $build), " \t\"");
    $hash = trim((string) pmssQbittorrentFrontendWebUiValue($config, 'Password_PBKDF2', $build), " \t\"");
    if (!ctype_digit($port) || (int) $port < 1024 || (int) $port > 65535 || $configured !== $port
        || preg_match('/\A@ByteArray\([A-Za-z0-9+\/=]+:[A-Za-z0-9+\/=]+\)\z/', $hash) !== 1) return false;
    if (!pmssQbittorrentFrontendRefusalClear($home)) return false;
    pmssQbittorrentFrontendNotice($home, 'qBittorrent WebUI port and password repaired; automatic start is permitted again.');
    return true;
}
