<?php
/** Customer-side Deluge and Transmission switch operations. */
require_once __DIR__.'/torrentClientsResolve.php';
require_once __DIR__.'/transmissionLaunch.php';

/** Use the existing customer native identity readers for the current selection. */
function pmssConfigureTorrentClientOtherPids($home, $client, $component = 'both')
{
    if ($client === 'transmission') {
        $binary = pmssTorrentClientResolve($home, $client);
        return $binary === null ? array() : pmssTransmissionNativePids($binary);
    }
    $binaries = pmssCustomerManagedAppDefinitions()['Deluge']['binaries'];
    $binary = pmssTorrentClientResolve($home, 'deluge');
    if ($binary !== null) {
        $binaries[] = $binary;
        $binaries[] = dirname($binary).'/deluge-web';
    }
    if ($component !== 'both') $binaries = array_values(array_filter($binaries,
        static function ($path) use ($component) { return basename($path) === $component; }));
    return pmssCustomerNativePidsRead($binaries);
}

/** Report the selected version only when a native client process is present. */
function pmssConfigureTorrentClientOtherRunningVersion($home, $client)
{
    if (pmssConfigureTorrentClientOtherPids($home, $client) === array()) return 'not running';
    $target = @readlink($home.'/bin/'.$client.'/default');
    return is_string($target) ? basename($target) : 'system';
}

/** Recheck native identity before TERM and wait for both Deluge components. */
function pmssConfigureTorrentClientOtherStop($home, $client)
{
    foreach (pmssConfigureTorrentClientOtherPids($home, $client) as $pid) {
        if (!in_array($pid, pmssConfigureTorrentClientOtherPids($home, $client), true)
            || !@posix_kill($pid, 15)) return false;
    }
    for ($attempt = 0; $attempt < 30; $attempt++) {
        if (pmssConfigureTorrentClientOtherPids($home, $client) === array()) return true;
        usleep(100000);
    }
    return false;
}

/** Atomically choose a checked directory and both venv entrypoints for Deluge. */
function pmssConfigureTorrentClientOtherRepoint($home, $client, $version, $restoreTarget = null)
{
    $path = $home.'/bin/'.$client.'/default';
    if (file_exists($path) && !is_link($path)) return false;
    if ($version === 'system') return $client === 'deluge' && (!is_link($path) || @unlink($path));
    $target = $restoreTarget === null ? '/home/.pmss/clients/'.$client.'/'.$version : $restoreTarget;
    if (!is_string($target) || $target === '') return false;
    $check = $target[0] === '/' ? $target : dirname($path).'/'.$target;
    $binary = $client === 'deluge' ? '/bin/deluged' : '/transmission-daemon';
    if (!is_dir($check) || is_link($check) || !is_file($check.$binary)
        || is_link($check.$binary)) return false;
    if ($client === 'deluge' && (!is_file($check.'/bin/deluge-web')
        || is_link($check.'/bin/deluge-web'))) return false;
    $temp = $path.'.tmp-'.getmypid();
    if (file_exists($temp) || is_link($temp) || !@symlink($target, $temp)) return false;
    if (@rename($temp, $path)) return true;
    @unlink($temp);
    return false;
}

/** Call the established launchers while the account switch lock is held. */
function pmssConfigureTorrentClientOtherStart($home, $client, $version)
{
    if ($client === 'transmission') {
        return pmssTransmissionSettingsPrepare($home) && pmssTransmissionLaunch($home);
    }
    if ($version !== 'system') return pmssTorrentClientDelugeLaunch($home);
    $rc = 1;
    @exec(pmssCustomerDelugeSystemCommand(), $unused, $rc);
    if ($rc !== 0) return false;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        if (pmssConfigureTorrentClientOtherPids($home, 'deluge', 'deluged') !== array()
            && pmssConfigureTorrentClientOtherPids($home, 'deluge', 'deluge-web') !== array()) return true;
        usleep(100000);
    }
    return false;
}
