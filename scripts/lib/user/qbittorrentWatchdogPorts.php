<?php
/** Native qBittorrent config and proxy convergence after root port assignment. @license GPL-3.0-only */
require_once __DIR__.'/torrentPort.php';

/** A running daemon must exit before Qt's saved port can be changed safely. */
function pmssQbittorrentWatchdogPortDiffers(string $user, string $home, int $assigned): bool
{
    $path = $home.'/.config/qBittorrent/qBittorrent.conf';
    if (!pmssTorrentPortUserHomeContextIsSafe($user, $home) || !pmssUserFilePathIsSafe($path)) return false;
    $config = pmssReadRegularFileContents($path);
    if (!is_string($config)) return false;
    $port = trim((string) pmssQbittorrentWebUiValue($config, 'Port', pmssQbittorrentBuildVersion($home)), " \t\"");
    return $port !== '' && $port !== (string) $assigned;
}

/** Preserve unchanged fragment mtimes; the lighttpd watchdog observes actual route changes. */
function pmssQbittorrentWatchdogProxyEnsure(string $user, string $home, int $assigned, array $ops = []): bool
{
    if (!pmssTorrentPortUserHomeContextIsSafe($user, $home) || !pmssNetworkPortInRange($assigned, 1024, 65500)) return false;
    $directory = $home.'/.lighttpd/custom.d';
    // Accounts without a panel do not need one to run the native client.
    if (!pmssPathExistsOrLink($directory)) return true;
    if (!pmssPathTargetIsSafe($directory, true)) return false;
    $path = $directory.'/pmss-qbittorrent.conf';
    $fragment = pmssLighttpdManagedProxyFragment('qbittorrent', $user, $assigned);
    if (pmssReadRegularFileContents($path) === $fragment) return true;
    $write = $ops['write'] ?? 'pmssLighttpdWriteManagedProxyFragment';
    return $write('qbittorrent', $user, $assigned, $path);
}
