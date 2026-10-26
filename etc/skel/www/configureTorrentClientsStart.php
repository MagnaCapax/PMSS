<?php
/** qBittorrent launch and process-version verification under the switch lock. */
/** Start under the held switch lock; the watchdog uses its nonblocking peer. */
function pmssConfigureTorrentClientStart($home, $version, $ops = array())
{
    $resolve = $ops['resolve'] ?? 'pmssTorrentClientResolve';
    $binary = $version === 'system' ? 'qbittorrent-nox' : $resolve($home, 'qbittorrent');
    if (!is_string($binary) || ($version !== 'system' && !is_file($binary))) {
        pmssQbittorrentFrontendNotice($home, 'qBittorrent start refused: the selected binary is unavailable.');
        return false;
    }
    // A successful -d can mean handoff; observe the asked build even before its WebUI appears.
    $expected = $version === 'custom' ? 'custom build' : (string) $version;
    $runningVersion = $ops['runningVersion'] ?? 'pmssConfigureTorrentClientRunningVersion';
    $matched = false;
    $ops['observed'] = static function () use ($home, $expected, $runningVersion, &$matched): bool {
        $matched = $runningVersion($home) === $expected || $matched;
        return $matched;
    };
    $since = null;
    $clock = $ops['clock'] ?? static function (): float { return microtime(true); };
    $ops['ready'] = $ops['ready'] ?? static function () use ($home, $expected, $runningVersion, &$since, $clock): bool {
        if ($runningVersion($home) !== $expected) { $since = null; return false; }
        $since = $since ?? $clock();
        return $clock() - $since >= 3;
    };
    $launch = $ops['launch'] ?? static function () use ($home, $binary): bool {
        $rc = 1;
        @exec('cd '.escapeshellarg($home).' && '.escapeshellarg($binary).' -d >/dev/null 2>&1', $unused, $rc);
        return $rc === 0;
    };
    return pmssQbittorrentFrontendStart($home, $launch, $ops + ['binary' => $binary]) && $matched;
}
