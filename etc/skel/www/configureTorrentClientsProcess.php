<?php
/** Account-native qBittorrent identity and shutdown. */
/** Check current UID, process name, mount namespace and executable location. */
function pmssConfigureTorrentClientNativePids($home, $procRoot = '/proc')
{
    $ownNs = @readlink('/proc/self/ns/mnt');
    if (!is_string($ownNs) || $ownNs === '') return array();
    $uid = posix_geteuid();
    $roots = array('/home/.pmss/clients/qbittorrent/', $home.'/bin/qbittorrent/', '/opt/qBittorrent/');
    $pids = array();
    foreach ((array) glob(rtrim($procRoot, '/').'/[0-9]*', GLOB_ONLYDIR) as $dir) {
        $pid = (int) basename($dir);
        if ($pid < 1 || @readlink($dir.'/ns/mnt') !== $ownNs
            || trim((string) @file_get_contents($dir.'/comm')) !== 'qbittorrent-nox') continue;
        $status = @file_get_contents($dir.'/status');
        if (!is_string($status) || preg_match('/^Uid:\s+(\d+)/m', $status, $match) !== 1
            || (int) $match[1] !== $uid) continue;
        $exe = @readlink($dir.'/exe');
        if (!is_string($exe)) continue;
        if (substr($exe, -10) === ' (deleted)') $exe = substr($exe, 0, -10);
        if ($exe === '/usr/bin/qbittorrent-nox' || $exe === '/usr/local/bin/qbittorrent-nox'
            || $exe === $home.'/bin/qbittorrent-nox') {
            $pids[] = $pid;
            continue;
        }
        foreach ($roots as $root) if (strpos($exe, $root) === 0) { $pids[] = $pid; break; }
    }
    return $pids;
}

/** Return the version the account has selected for $client, as the version list names it. */
function pmssConfigureTorrentClientSelectedVersion($home, $client)
{
    $target = @readlink($home.'/bin/'.$client.'/default');
    return is_string($target) ? basename($target) : 'system';
}

/** Show the version of the current native process from its kernel exe path. */
function pmssConfigureTorrentClientRunningVersion($home, $ops = array())
{
    $nativePids = $ops['pids'] ?? 'pmssConfigureTorrentClientNativePids';
    $resolve = $ops['resolve'] ?? 'pmssTorrentClientResolve';
    $pids = $nativePids($home);
    $target = @readlink($home.'/bin/qbittorrent/default');
    if ($pids !== array() && is_string($target) && strpos($target, '/home/.pmss/clients/qbittorrent/') === 0
        && $resolve($home, 'qbittorrent') === null)
        return 'system; '.basename($target).' is not installed on this server';
    foreach ($pids as $pid) {
        $exe = @readlink('/proc/'.$pid.'/exe');
        if (!is_string($exe)) continue;
        if (strpos($exe, '/home/.pmss/clients/qbittorrent/') === 0) {
            $rest = substr($exe, strlen('/home/.pmss/clients/qbittorrent/'));
            return explode('/', $rest, 2)[0];
        }
        if (strpos($exe, $home.'/bin/qbittorrent/') === 0) return 'custom build';
        return 'system';
    }
    return 'not running';
}
/** Re-enumerate before each TERM, then wait for the native daemon to leave. */
function pmssConfigureTorrentClientStop($home, array $ops = [])
{
    $pids = $ops['pids'] ?? 'pmssConfigureTorrentClientNativePids';
    $signal = $ops['signal'] ?? static function ($pid, $signal): bool { return @posix_kill($pid, $signal); };
    $pause = $ops['pause'] ?? static function (): void { usleep(100000); };
    foreach ($pids($home) as $pid) {
        if (!in_array($pid, $pids($home), true) || !$signal($pid, 15)) return false;
    }
    // Saving resume data for many torrents takes far longer than a few seconds; starting early hands off to the exiting daemon.
    for ($attempt = 0; $attempt < 600; $attempt++) {
        if ($pids($home) === array()) return true;
        $pause();
    }
    return false;
}
