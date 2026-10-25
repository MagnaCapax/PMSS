<?php
/** Customer-owned qBittorrent switch mechanics and shared version reader. */
require_once __DIR__.'/torrentClientsResolve.php';
require_once __DIR__.'/configureTorrentClientsVersion.php';
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
function pmssConfigureTorrentClientStop($home)
{
    foreach (pmssConfigureTorrentClientNativePids($home) as $pid) {
        if (!in_array($pid, pmssConfigureTorrentClientNativePids($home), true)
            || !@posix_kill($pid, 15)) return false;
    }
    // Saving resume data for many torrents takes far longer than a few seconds; starting early hands off to the exiting daemon.
    for ($attempt = 0; $attempt < 600; $attempt++) {
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
    // Recovery may need to restore an already-invalid link verbatim.
    if (($version !== 'invalid' || $restoreTarget === null) && (!is_dir($check) || is_link($check)
        || !is_file($check.'/qbittorrent-nox') || is_link($check.'/qbittorrent-nox'))) return false;
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
    if ($rc !== 0) return false;
    // qbittorrent-nox -d exits 0 even when it handed off to another instance; started means the asked build runs.
    $expected = $version === 'custom' ? 'custom build' : (string) $version;
    for ($attempt = 0; $attempt < 200; $attempt++) {
        if (pmssConfigureTorrentClientRunningVersion($home) === $expected) {
            sleep(3);
            return pmssConfigureTorrentClientRunningVersion($home) === $expected;
        }
        usleep(100000);
    }
    return false;
}
/** Snapshot, stop, select and start under one account-owned client lock. */
function pmssConfigureTorrentClientSwitch($home, $request, $ops = array())
{
    $versions = isset($ops['versions']) ? $ops['versions'] : pmssConfigureTorrentClientVersionsOffered();
    $version = $request['version'] ?? null;
    if (($request['client'] ?? null) !== 'qbittorrent' || !is_string($version)
        || !isset($versions[$version])) return array('ok' => false, 'status' => 400, 'message' => 'That qBittorrent version is unavailable.');
    if (!is_dir($home.'/bin/qbittorrent') || is_link($home.'/bin/qbittorrent'))
        return array('ok' => false, 'status' => 503, 'message' => 'The client link directory is unavailable.');
    $dir = $home.'/.config/pmss/torrentClients';
    if ((!is_dir($dir) && !@mkdir($dir, 0700, true)) || is_link($dir))
        return array('ok' => false, 'status' => 503, 'message' => 'The switch lock is unavailable.');
    $lock = @fopen($dir.'/qbittorrent.lock', 'ce');
    if (!is_resource($lock) || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        return array('ok' => false, 'status' => 409, 'message' => 'A qBittorrent switch is already running.');
    }
    $link = $home.'/bin/qbittorrent/default';
    $old = @readlink($link);
    $resolve = $ops['resolve'] ?? 'pmssTorrentClientResolve';
    $invalid = is_string($old) && $resolve($home, 'qbittorrent') === null;
    if ($invalid && $version !== 'system') {
        flock($lock, LOCK_UN);
        fclose($lock);
        return array('ok' => false, 'status' => 409, 'message' => 'The current selection is invalid.');
    }
    $oldVersion = is_string($old) && strpos($old, '/home/.pmss/clients/qbittorrent/') === 0
        ? basename($old) : (is_string($old) ? 'custom' : 'system');
    if ($invalid) $oldVersion = 'invalid';
    // A selection alone is not proof: stopped or different native builds still need the restart.
    $runningVersion = $ops['runningVersion'] ?? 'pmssConfigureTorrentClientRunningVersion';
    if ($version === $oldVersion && $runningVersion($home) === $version) {
        flock($lock, LOCK_UN);
        fclose($lock);
        return array('ok' => true, 'status' => 200, 'message' => 'qBittorrent is already running '
            .pmssConfigureTorrentClientVersionLabel($version).'; nothing changed.');
    }
    $lastRun = trim(pmssConfigureFileRead($dir.'/qbittorrent.lastRun', 64));
    if (pmssConfigureTorrentClientMajorDowngrade($lastRun, $version)) {
        $message = 'Downgrading qBittorrent from '.$lastRun.' to '.$version
            .' can corrupt existing data; switch refused — open a ticket for a data-preserving rollback.';
        pmssConfigureFileWrite($dir.'/qbittorrent.notice', $message."\n");
        flock($lock, LOCK_UN);
        fclose($lock);
        return array('ok' => false, 'status' => 409, 'message' => $message);
    }
    $backup = $ops['backup'] ?? 'pmssCustomerBackupFileCreate';
    $stop = $ops['stop'] ?? 'pmssConfigureTorrentClientStop';
    $start = $ops['start'] ?? 'pmssConfigureTorrentClientStart';
    $repoint = $ops['repoint'] ?? 'pmssConfigureTorrentClientRepoint';
    $snapshot = $backup($home);
    if (!is_array($snapshot) || empty($snapshot['ok'])) {
        $detail = is_string($snapshot['message'] ?? null) ? $snapshot['message'] : 'Backup failed';
        $message = 'Could not back up the current configuration: '.rtrim($detail, ". \r\n").'. Nothing was changed.';
        pmssConfigureFileWrite($dir.'/qbittorrent.notice', $message."\n");
        flock($lock, LOCK_UN);
        fclose($lock);
        return array('ok' => false, 'status' => 503, 'message' => $message);
    }
    $stopped = $stop($home);
    $repointed = $stopped && $repoint($home, $version);
    $ok = $repointed && pmssConfigureFileWrite($dir.'/qbittorrent.lastRun', $version."\n")
        && $start($home, $version) && pmssConfigureFileWrite($dir.'/qbittorrent.lastgood', $version."\n");
    // The switch starts the chosen build, so Apps shows qBittorrent on and the watchdog keeps it running.
    if ($ok) @touch($home.'/.qbittorrentEnable');
    $restored = true;
    if (!$ok && $stopped) {
        $lastgood = trim(pmssConfigureFileRead($dir.'/qbittorrent.lastgood', 64));
        $restore = $lastgood === $oldVersion ? $lastgood : $oldVersion;
        $restored = $repointed ? ($stop($home) && $repoint($home, $restore, $old) && $start($home, $restore))
            : $start($home, $restore);
        if ($restored) pmssConfigureFileWrite($dir.'/qbittorrent.lastRun', $restore."\n");
    }
    pmssConfigureFileWrite($dir.'/qbittorrent.notice', $ok
        ? 'qBittorrent selected: '.pmssConfigureTorrentClientVersionLabel($version)."\n" : ($restored ? 'qBittorrent switch failed; previous version restored.'
            : 'qBittorrent switch failed; manual recovery is needed.')."\n");
    flock($lock, LOCK_UN);
    fclose($lock);
    return array('ok' => $ok, 'status' => $ok ? 200 : 503,
        'message' => $ok ? 'qBittorrent switched to '.pmssConfigureTorrentClientVersionLabel($version).'.' : ($restored
            ? 'The switch failed; the previous version was restored.' : 'The switch failed; manual recovery is needed.'));
}
