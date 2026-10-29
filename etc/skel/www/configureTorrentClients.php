<?php
/** Customer-owned qBittorrent switch mechanics and shared version reader. */
require_once __DIR__.'/torrentClientsResolve.php';
require_once __DIR__.'/configureTorrentClientsVersion.php';
require_once __DIR__.'/qbittorrentStart.php';
require_once __DIR__.'/configureTorrentClientsProcess.php';
require_once __DIR__.'/configureTorrentClientsStart.php';
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
/** Snapshot, stop, select and start under one account-owned client lock. */
function pmssConfigureTorrentClientSwitchApply($home, $request, $ops = array())
{
    $versions = isset($ops['versions']) ? $ops['versions'] : pmssConfigureTorrentClientVersionsOffered();
    $version = $request['version'] ?? null;
    if (($request['client'] ?? null) !== 'qbittorrent' || !is_string($version)
        || !isset($versions[$version])) return array('ok' => false, 'status' => 400, 'message' => 'That qBittorrent version is unavailable.');
    if (!is_dir($home.'/bin/qbittorrent') || is_link($home.'/bin/qbittorrent'))
        return array('ok' => false, 'status' => 503, 'message' => 'The client link directory is unavailable.');
    $dir = $home.'/.config/pmss/torrentClients';
    if (function_exists('pmssConfigureTorrentClientLockOpen')) {
        list($lock, $refused) = pmssConfigureTorrentClientLockOpen($dir, 'qbittorrent', 'qBittorrent');
        if ($refused !== null) return $refused;
    } else {
        if ((!is_dir($dir) && !@mkdir($dir, 0700, true)) || is_link($dir))
            return array('ok' => false, 'status' => 503, 'message' => 'The switch lock is unavailable.');
        $lock = @fopen($dir.'/qbittorrent.lock', 'ce');
        if (!is_resource($lock) || !@flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            return array('ok' => false, 'status' => 409, 'message' => 'A qBittorrent switch is already running.');
        }
    }
    if (!pmssQbittorrentFrontendRefusalClear($home)) {
        flock($lock, LOCK_UN);
        fclose($lock);
        return array('ok' => false, 'status' => 409, 'message' => 'The qBittorrent refusal marker could not be cleared.');
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
    $stop = $ops['stop'] ?? static function ($home) use ($ops): bool { return pmssConfigureTorrentClientStop($home, $ops); };
    $start = $ops['start'] ?? static function ($home, $version) use ($ops): bool { return pmssConfigureTorrentClientStart($home, $version, $ops); };
    $restoreConfig = $ops['restore'] ?? static function ($home) use ($ops): bool { return pmssQbittorrentFrontendRestore($home, $ops); };
    $repoint = $ops['repoint'] ?? 'pmssConfigureTorrentClientRepoint';
    error_clear_last();
    $snapshot = $backup($home);
    if (!is_array($snapshot) || empty($snapshot['ok'])) {
        $refusal = pmssConfigureTorrentClientBackupRefusal($snapshot);
        pmssConfigureFileWrite($dir.'/qbittorrent.notice', $refusal['message']."\n");
        flock($lock, LOCK_UN);
        fclose($lock);
        return $refusal;
    }
    $stopped = $stop($home);
    $repointed = $stopped && $repoint($home, $version);
    $ok = $repointed && $restoreConfig($home) && $start($home, $version)
        && pmssConfigureFileWrite($dir.'/qbittorrent.lastRun', $version."\n")
        && pmssConfigureFileWrite($dir.'/qbittorrent.lastgood', $version."\n");
    // The switch starts the chosen build, so Apps shows qBittorrent on and the watchdog keeps it running.
    if ($ok) @touch($home.'/.qbittorrentEnable');
    $restored = true;
    if (!$ok && $stopped) {
        $lastgood = trim(pmssConfigureFileRead($dir.'/qbittorrent.lastgood', 64));
        $restore = $lastgood === $oldVersion ? $lastgood : $oldVersion;
        $restored = $repointed ? ($stop($home) && $repoint($home, $restore, $old)
            && $restoreConfig($home) && $start($home, $restore))
            : ($restoreConfig($home) && $start($home, $restore));
        if ($restored) pmssConfigureFileWrite($dir.'/qbittorrent.lastRun', $restore."\n");
    }
    $linkRefused = is_link($home.'/.config/qBittorrent/qBittorrent.conf');
    pmssConfigureFileWrite($dir.'/qbittorrent.notice', $ok
        ? 'qBittorrent selected: '.pmssConfigureTorrentClientVersionLabel($version)."\n" : ($restored ? 'qBittorrent switch failed; previous version restored.'
            : 'qBittorrent switch failed; manual recovery is needed.')."\n");
    flock($lock, LOCK_UN);
    fclose($lock);
    return array('ok' => $ok, 'status' => $ok ? 200 : 503,
        'message' => $linkRefused && !$ok ? 'qBittorrent.conf is a link; PMSS will not write through it' : ($ok ? 'qBittorrent switched to '.pmssConfigureTorrentClientVersionLabel($version).'.' : ($restored
            ? 'The switch failed; the previous version was restored.' : 'The switch failed; manual recovery is needed.')));
}

/** Publish each refusal returned before the start path can write its own notice. */
function pmssConfigureTorrentClientSwitch($home, $request, $ops = array())
{
    $result = pmssConfigureTorrentClientSwitchApply($home, $request, $ops);
    if (!$result['ok']) pmssQbittorrentFrontendNotice($home, $result['message']);
    return $result;
}
