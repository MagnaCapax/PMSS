<?php
/** Password-guarded Configure switch for the additional native clients. */
require_once __DIR__.'/configureTorrentClients.php';
require_once __DIR__.'/configureTorrentClientsOther.php';

/** Snapshot, stop, select and start with the same account-owned state contract. */
function pmssConfigureTorrentClientOtherSwitch($home, $request, $ops = array())
{
    $client = $request['client'] ?? null;
    if (!in_array($client, array('deluge', 'transmission', 'rtorrent'), true))
        return array('ok' => false, 'status' => 400, 'message' => 'That torrent client is unavailable.');
    $label = array('deluge' => 'Deluge', 'transmission' => 'Transmission', 'rtorrent' => 'rTorrent')[$client];
    $versions = isset($ops['versions']) ? $ops['versions']
        : pmssConfigureTorrentClientVersionsOffered($client);
    $version = $request['version'] ?? null;
    if (!is_array($versions) || !is_string($version)
        || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $version) !== 1
        || !isset($versions[$version]) || !is_array($versions[$version])
        || !in_array($versions[$version]['kind'] ?? null,
            $client === 'deluge' ? array('system', 'venv')
                : ($client === 'rtorrent' ? array('system', 'static') : array('static')), true)
        || (($version === 'system') !== (($versions[$version]['kind'] ?? null) === 'system'))
        || ($client === 'transmission' && ($versions[$version]['rpcUnixSocket'] ?? null) !== true))
        return array('ok' => false, 'status' => 400, 'message' => 'That '.$client.' version is unavailable.');
    if (!is_dir($home.'/bin/'.$client) || is_link($home.'/bin/'.$client))
        return array('ok' => false, 'status' => 503, 'message' => 'The client link directory is unavailable.');
    if ($client === 'rtorrent' && is_file($home.'/.rtorrentDisable'))
        return array('ok' => false, 'status' => 409, 'message' => 'Start rTorrent in Apps before switching versions.');
    if ($client === 'transmission' && is_file($home.'/.transmissionDisable'))
        return array('ok' => false, 'status' => 409, 'message' => 'Start Transmission in Apps before switching versions.');
    $dir = $home.'/.config/pmss/torrentClients';
    if ((!is_dir($dir) && !@mkdir($dir, 0700, true)) || is_link($dir))
        return array('ok' => false, 'status' => 503, 'message' => 'The switch lock is unavailable.');
    $lock = @fopen($dir.'/'.$client.'.lock', 'ce');
    if (!is_resource($lock) || !@flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        return array('ok' => false, 'status' => 409, 'message' => 'A '.$client.' switch is already running.');
    }
    $link = $home.'/bin/'.$client.'/default';
    if (file_exists($link) && !is_link($link)) {
        flock($lock, LOCK_UN);
        fclose($lock);
        return array('ok' => false, 'status' => 409, 'message' => 'The current selection is invalid.');
    }
    $old = @readlink($link);
    $resolve = $ops['resolve'] ?? 'pmssTorrentClientResolve';
    $invalid = is_string($old) && $resolve($home, $client) === null;
    if ($invalid && $version !== 'system') {
        flock($lock, LOCK_UN);
        fclose($lock);
        return array('ok' => false, 'status' => 409, 'message' => 'The current selection is invalid.');
    }
    $oldVersion = is_string($old) && strpos($old, '/home/.pmss/clients/'.$client.'/') === 0
        ? basename($old) : (is_string($old) ? 'custom' : 'system');
    if ($invalid) $oldVersion = 'invalid';
    // Exact equality excludes stopped clients and rTorrent's descriptive system fallback states.
    $runningVersion = $ops['runningVersion'] ?? 'pmssConfigureTorrentClientOtherRunningVersion';
    if ($version === $oldVersion && $runningVersion($home, $client) === $version) {
        flock($lock, LOCK_UN);
        fclose($lock);
        return array('ok' => true, 'status' => 200, 'message' => $label.' is already running '
            .pmssConfigureTorrentClientVersionLabel($version).'; nothing changed.');
    }
    $lastRun = trim(pmssConfigureFileRead($dir.'/'.$client.'.lastRun', 64));
    if (pmssConfigureTorrentClientMajorDowngrade($lastRun, $version)) {
        $message = 'Downgrading '.$label.' from '.$lastRun.' to '.$version
            .' can corrupt existing data; switch refused — open a ticket for a data-preserving rollback.';
        pmssConfigureFileWrite($dir.'/'.$client.'.notice', $message."\n");
        flock($lock, LOCK_UN);
        fclose($lock);
        return array('ok' => false, 'status' => 409, 'message' => $message);
    }
    $backup = $ops['backup'] ?? 'pmssCustomerBackupFileCreate';
    $stop = $ops['stop'] ?? 'pmssConfigureTorrentClientOtherStop';
    $start = $ops['start'] ?? 'pmssConfigureTorrentClientOtherStart';
    $repoint = $ops['repoint'] ?? 'pmssConfigureTorrentClientOtherRepoint';
    $snapshot = $backup($home);
    if (!is_array($snapshot) || empty($snapshot['ok'])) {
        $detail = is_string($snapshot['message'] ?? null) ? $snapshot['message'] : 'Backup failed';
        $message = 'Could not back up the current configuration: '.rtrim($detail, ". \r\n").'. Nothing was changed.';
        pmssConfigureFileWrite($dir.'/'.$client.'.notice', $message."\n");
        flock($lock, LOCK_UN);
        fclose($lock);
        return array('ok' => false, 'status' => 503, 'message' => $message);
    }
    $stopped = $stop($home, $client);
    $repointed = $stopped && $repoint($home, $client, $version);
    $ok = $repointed && pmssConfigureFileWrite($dir.'/'.$client.'.lastRun', $version."\n")
        && $start($home, $client, $version)
        && pmssConfigureFileWrite($dir.'/'.$client.'.lastgood', $version."\n");
    // The switch starts the chosen build, so Apps shows Deluge on and the watchdog keeps it running.
    if ($ok && $client === 'deluge') @touch($home.'/.delugeEnable');
    $restored = true;
    if (!$ok && $stopped) {
        $lastgood = trim(pmssConfigureFileRead($dir.'/'.$client.'.lastgood', 64));
        $restore = $lastgood === $oldVersion ? $lastgood : $oldVersion;
        $restored = $repointed ? ($stop($home, $client) && $repoint($home, $client, $restore, $old)
            && ($restore === 'system' && $client === 'transmission' || $start($home, $client, $restore)))
            : ($oldVersion === 'system' && $client === 'transmission' || $start($home, $client, $restore));
        if ($restored) pmssConfigureFileWrite($dir.'/'.$client.'.lastRun', $restore."\n");
    }
    pmssConfigureFileWrite($dir.'/'.$client.'.notice', $ok
        ? $label.' selected: '.$version."\n" : ($restored
            ? $label.' switch failed; previous version restored.'
            : $label.' switch failed; manual recovery is needed.')."\n");
    flock($lock, LOCK_UN);
    fclose($lock);
    return array('ok' => $ok, 'status' => $ok ? 200 : 503,
        'message' => $ok ? $label.' switched to '.$version.'.' : ($restored
            ? 'The switch failed; the previous version was restored.' : 'The switch failed; manual recovery is needed.'));
}
