<?php
/** A config writer must wait for every same-UID qBittorrent, including user services. */

/** Name and UID alone guard writes; mount namespaces and install roots do not. */
function pmssQbittorrentFrontendAccountPids(string $proc = '/proc', ?int $uid = null): array
{
    $uid = $uid ?? posix_geteuid();
    $pids = [];
    foreach (glob(rtrim($proc, '/').'/[0-9]*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (trim((string) @file_get_contents($dir.'/comm')) !== 'qbittorrent-nox') continue;
        $status = @file_get_contents($dir.'/status');
        if (is_string($status) && preg_match('/^Uid:\s+(\d+)/m', $status, $match) === 1
            && (int) $match[1] === $uid) $pids[] = (int) basename($dir);
    }
    return $pids;
}
