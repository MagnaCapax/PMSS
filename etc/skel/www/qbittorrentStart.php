<?php
/** One guarded account start for system and selected qBittorrent binaries. */
require_once __DIR__.'/qbittorrentStoppedConfig.php';
require_once __DIR__.'/qbittorrentSocket.php';
require_once __DIR__.'/qbittorrentThrottleStart.php';

/** Restore only while stopped, then verify the listener after the supplied launch. */
function pmssQbittorrentFrontendStart(string $home, callable $launch, array $ops = []): bool
{
    $restore = $ops['restore'] ?? 'pmssQbittorrentFrontendRestore';
    $notice = $ops['notice'] ?? 'pmssQbittorrentFrontendNotice';
    $check = $ops['check'] ?? static function (string $home) use ($ops): bool {
        return pmssQbittorrentFrontendSocketWait($home, $ops);
    };
    $pids = $ops['accountPids'] ?? 'pmssQbittorrentFrontendAccountPids';
    if ($pids() !== []) {
        $notice($home, 'qBittorrent is already running; stop it before requesting another start.');
        return false;
    }
    $build = $ops['build'] ?? pmssQbittorrentFrontendBuildVersion($home, $ops['binary'] ?? null, $ops);
    if (!$restore($home, $ops + ['build' => $build])) {
        // Restore owns its detailed refusal text, including link and busy notices.
        if (isset($ops['restore'])) $notice($home, 'qBittorrent start refused: the stopped configuration could not be restored.');
        return false;
    }
    $throttle = $ops['throttle'] ?? 'pmssQbittorrentFrontendThrottleStart';
    if (!$throttle($home, $ops + ['build' => $build])) return false;
    if ($launch() === false) {
        $notice($home, 'qBittorrent launch failed; the requested binary did not start.');
        return false;
    }
    if (!$check($home)) {
        if (!file_exists($home.'/.config/pmss/torrentClients/qbittorrent.refused'))
            $notice($home, 'qBittorrent start failed: the requested build is no longer running or was not observed.');
        return false;
    }
    pmssQbittorrentFrontendBuildRecord($home, $build);
    $clear = $ops['noticeClear'] ?? 'pmssQbittorrentFrontendNoticeClear';
    if (is_callable($clear)) $clear($home);
    return true;
}
