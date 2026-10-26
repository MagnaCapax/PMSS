<?php
/** Account-locked resolver start for selected and system qBittorrent builds. */
/** Start a selected client as the account while excluding a concurrent switch. */
function pmssTorrentClientResolverLaunch(string $home, string $client, bool $lockHeld = false, array $ops = []): bool
{
    if ($client !== 'qbittorrent' || (!is_link($home.'/bin/'.$client.'/default') && empty($ops['system']))) return false;
    require_once __DIR__.'/configureTorrentClients.php';
    $lock = null;
    if (!$lockHeld) {
        $dir = $home.'/.config/pmss/torrentClients';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true))
            return pmssQbittorrentFrontendStartRefused($home, 'qBittorrent start refused: the operation directory is unavailable.');
        if (is_link($dir)) return pmssQbittorrentFrontendStartRefused($home, 'qBittorrent start refused: the operation directory is a link.');
        $lock = @fopen($dir.'/'.$client.'.lock', 'ce');
        if (!is_resource($lock)) return pmssQbittorrentFrontendStartRefused($home, 'qBittorrent start refused: the operation lock could not be opened.');
        if (!@flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return true; }
    }
    require_once __DIR__.'/configureTorrentClients.php';
    $refused = $home.'/.config/pmss/torrentClients/qbittorrent.refused';
    if (file_exists($refused) || is_link($refused)) {
        pmssQbittorrentFrontendNotice($home, 'qBittorrent start refused after an unexpected default WebUI listener; repair the WebUI port and password or retry in Apps.');
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        return false;
    }
    $resolve = $ops['resolve'] ?? 'pmssTorrentClientResolve';
    $binary = is_link($home.'/bin/'.$client.'/default') ? $resolve($home, $client) : 'qbittorrent-nox';
    $launch = $ops['launch'] ?? static function () use ($binary): bool {
        $rc = 1;
        @exec(escapeshellarg($binary).' -d >/dev/null 2>&1', $unused, $rc);
        return $rc === 0;
    };
    if ($binary === null) pmssQbittorrentFrontendNotice($home, 'qBittorrent start refused: the selected binary is unavailable.');
    $ok = $binary !== null && pmssQbittorrentFrontendStart($home, $launch, $ops + ['binary' => $binary]);
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    return $ok;
}
