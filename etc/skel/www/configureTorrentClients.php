<?php
/** Customer-owned qBittorrent switch mechanics and shared version reader. */
require_once __DIR__.'/torrentClientsResolve.php';
/** Read only installed versions from the root-published projection. */
function pmssConfigureTorrentClientVersionsRead($path = '/var/lib/pmss/public/torrentClients.json', $client = 'qbittorrent')
{
    if (!is_file($path) || is_link($path) || @filesize($path) > 65536) return array();
    $data = json_decode((string) @file_get_contents($path), true);
    $versions = array();
    foreach ((array) ($data['versions'] ?? array()) as $entry) {
        if (!is_array($entry) || ($entry['client'] ?? '') !== $client
            || !is_string($entry['version'] ?? null)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $entry['version']) !== 1
            || !in_array($entry['kind'] ?? '', array('system', 'static', 'venv'), true)) continue;
        $versions[$entry['version']] = $entry;
    }
    return $versions;
}
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
        if ($exe === '/usr/bin/qbittorrent-nox' || $exe === '/usr/local/bin/qbittorrent-nox') {
            $pids[] = $pid;
            continue;
        }
        foreach ($roots as $root) if (strpos($exe, $root) === 0) { $pids[] = $pid; break; }
    }
    return $pids;
}
/** Show the version of the current native process from its kernel exe path. */
function pmssConfigureTorrentClientRunningVersion($home)
{
    foreach (pmssConfigureTorrentClientNativePids($home) as $pid) {
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
function pmssConfigureTorrentClientStop($home)
{
    foreach (pmssConfigureTorrentClientNativePids($home) as $pid) {
        if (!in_array($pid, pmssConfigureTorrentClientNativePids($home), true)
            || !@posix_kill($pid, 15)) return false;
    }
    for ($attempt = 0; $attempt < 30; $attempt++) {
        if (pmssConfigureTorrentClientNativePids($home) === array()) return true;
        usleep(100000);
    }
    return false;
}
/** Rename a same-directory symlink so readers see one complete selection. */
function pmssConfigureTorrentClientRepoint($home, $version, $restoreTarget = null)
{
    $path = $home.'/bin/qbittorrent/default';
    if (file_exists($path) && !is_link($path)) return false;
    if ($version === 'system') return !is_link($path) || @unlink($path);
    $target = $restoreTarget === null ? '/home/.pmss/clients/qbittorrent/'.$version : $restoreTarget;
    $check = $target[0] === '/' ? $target : dirname($path).'/'.$target;
    if (!is_dir($check) || is_link($check) || !is_file($check.'/qbittorrent-nox')
        || is_link($check.'/qbittorrent-nox')) return false;
    $temp = $path.'.tmp-'.getmypid();
    if (file_exists($temp) || is_link($temp) || !@symlink($target, $temp)) return false;
    if (@rename($temp, $path)) return true;
    @unlink($temp);
    return false;
}
/** Start under the held switch lock; the watchdog uses its nonblocking peer. */
function pmssConfigureTorrentClientStart($home, $version)
{
    $binary = $version === 'system' ? 'qbittorrent-nox'
        : pmssTorrentClientResolve($home, 'qbittorrent');
    if (!is_string($binary) || ($version !== 'system' && !is_file($binary))) return false;
    $rc = 1;
    @exec('cd '.escapeshellarg($home).' && '.escapeshellarg($binary).' -d >/dev/null 2>&1', $unused, $rc);
    return $rc === 0;
}
/** Snapshot, stop, select and start under one account-owned client lock. */
function pmssConfigureTorrentClientSwitch($home, $request, $ops = array())
{
    $versions = isset($ops['versions']) ? $ops['versions'] : pmssConfigureTorrentClientVersionsRead();
    $version = $request['version'] ?? null;
    if (($request['client'] ?? null) !== 'qbittorrent' || !is_string($version)
        || !isset($versions[$version])) return array('ok' => false, 'status' => 400, 'message' => 'That qBittorrent version is unavailable.');
    if (!is_dir($home.'/bin/qbittorrent') || is_link($home.'/bin/qbittorrent'))
        return array('ok' => false, 'status' => 503, 'message' => 'The client link directory is unavailable.');
    $dir = $home.'/.config/pmss/torrentClients';
    if ((!is_dir($dir) && !@mkdir($dir, 0700, true)) || is_link($dir))
        return array('ok' => false, 'status' => 503, 'message' => 'The switch lock is unavailable.');
    $lock = @fopen($dir.'/qbittorrent.lock', 'c');
    if (!is_resource($lock) || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        return array('ok' => false, 'status' => 409, 'message' => 'A qBittorrent switch is already running.');
    }
    $link = $home.'/bin/qbittorrent/default';
    $old = @readlink($link);
    if (is_string($old) && pmssTorrentClientResolve($home, 'qbittorrent') === null) {
        flock($lock, LOCK_UN);
        fclose($lock);
        return array('ok' => false, 'status' => 409, 'message' => 'The current selection is invalid.');
    }
    $oldVersion = is_string($old) && strpos($old, '/home/.pmss/clients/qbittorrent/') === 0
        ? basename($old) : (is_string($old) ? 'custom' : 'system');
    $backup = $ops['backup'] ?? 'pmssCustomerBackupFileCreate';
    $stop = $ops['stop'] ?? 'pmssConfigureTorrentClientStop';
    $start = $ops['start'] ?? 'pmssConfigureTorrentClientStart';
    $repoint = $ops['repoint'] ?? 'pmssConfigureTorrentClientRepoint';
    $snapshot = $backup($home);
    $stopped = is_array($snapshot) && !empty($snapshot['ok']) && $stop($home);
    $repointed = $stopped && $repoint($home, $version);
    $ok = $repointed && pmssConfigureFileWrite($dir.'/qbittorrent.lastRun', $version."\n")
        && $start($home, $version) && pmssConfigureFileWrite($dir.'/qbittorrent.lastgood', $version."\n");
    $restored = true;
    if (!$ok && $stopped) {
        $lastgood = trim(pmssConfigureFileRead($dir.'/qbittorrent.lastgood', 64));
        $restore = $lastgood === $oldVersion ? $lastgood : $oldVersion;
        $restored = $repointed ? ($stop($home) && $repoint($home, $restore, $old) && $start($home, $restore))
            : $start($home, $restore);
        if ($restored) pmssConfigureFileWrite($dir.'/qbittorrent.lastRun', $restore."\n");
    }
    pmssConfigureFileWrite($dir.'/qbittorrent.notice', $ok
        ? 'qBittorrent selected: '.$version."\n" : ($restored ? 'qBittorrent switch failed; previous version restored.'
            : 'qBittorrent switch failed; manual recovery is needed.')."\n");
    flock($lock, LOCK_UN);
    fclose($lock);
    return array('ok' => $ok, 'status' => $ok ? 200 : 503,
        'message' => $ok ? 'qBittorrent switched to '.$version.'.' : ($restored
            ? 'The switch failed; the previous version was restored.' : 'The switch failed; manual recovery is needed.'));
}
