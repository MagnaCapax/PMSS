<?php
/** Customer-side native torrent-client switch operations. */
require_once __DIR__.'/torrentClientsResolve.php';
require_once __DIR__.'/transmissionLaunch.php';

/** Use the existing customer native identity readers for the current selection. */
function pmssConfigureTorrentClientOtherPids($home, $client, $component = 'both')
{
    if ($client === 'rtorrent') {
        $binaries = array('/usr/bin/rtorrent', '/usr/local/bin/rtorrent');
        $binary = pmssTorrentClientResolve($home, $client);
        if ($binary !== null) $binaries[] = $binary;
        return pmssCustomerNativePidsRead($binaries);
    }
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
    if (!is_string($target)) return 'system';
    // After three fast exits the executor runs the system build and leaves the selection alone (ADR 0146).
    $binary = pmssTorrentClientResolve($home, $client);
    if ($client === 'rtorrent' && $binary !== null && pmssCustomerNativePidsRead(array($binary)) === array())
        return pmssRtorrentExecutorFallbackVersion($home) === basename($target)
            ? 'system, because '.basename($target).' kept stopping right after start'
            : 'system; '.basename($target).' starts at the next rTorrent restart';
    return basename($target);
}

/** Recheck native identity before TERM and wait for both Deluge components. */
function pmssConfigureTorrentClientOtherStop($home, $client)
{
    // The rTorrent executor respawns children: restart it only after repointing.
    if ($client === 'rtorrent') return true;
    foreach (pmssConfigureTorrentClientOtherPids($home, $client) as $pid) {
        if (!in_array($pid, pmssConfigureTorrentClientOtherPids($home, $client), true)
            || !@posix_kill($pid, 15)) return false;
    }
    // Saving resume data for many torrents takes far longer than a few seconds.
    for ($attempt = 0; $attempt < 600; $attempt++) {
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
    if ($version === 'system') return in_array($client, array('deluge', 'rtorrent'), true)
        && (!is_link($path) || @unlink($path));
    $target = $restoreTarget === null ? '/home/.pmss/clients/'.$client.'/'.$version : $restoreTarget;
    if (!is_string($target) || $target === '') return false;
    $check = $target[0] === '/' ? $target : dirname($path).'/'.$target;
    $binary = $client === 'deluge' ? '/bin/deluged'
        : ($client === 'rtorrent' ? '/rtorrent' : '/transmission-daemon');
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
    if ($client === 'rtorrent') {
        $binary = $version === 'system' ? '/usr/local/bin/rtorrent' : pmssTorrentClientResolve($home, $client);
        // Account-local selections still use the executor's legacy system fallback.
        if ($binary !== null && pmssRtorrentExecutorCommand($home) === '/usr/local/bin/rtorrent')
            $binary = '/usr/local/bin/rtorrent';
        if ($binary === null || !is_file($home.'/.rtorrentRestart.php')
            || is_file($home.'/.rtorrentDisable') || !@touch($home.'/www/.rtorrentRestart')) return false;
        // Reuse Apps' restart consumer; it preserves the supervising executor.
        $rc = 1;
        @exec('cd '.escapeshellarg($home).' && php ./.rtorrentRestart.php >/dev/null 2>&1', $unused, $rc);
        if ($rc !== 0) return false;
        // The executor sleeps 5 s after rTorrent exits before it starts the selected build.
        for ($attempt = 0; $attempt < 150; $attempt++) {
            $pids = pmssCustomerNativePidsRead(array($binary));
            if ($pids !== array()) {
                // A build that rejects the account's rc exits at once and the executor retries every
                // 5 s, so only a process still running after that counts as started.
                sleep(7);
                return array_intersect($pids, pmssCustomerNativePidsRead(array($binary))) !== array();
            }
            usleep(100000);
        }
        return false;
    }
    if ($client === 'transmission') {
        return !is_file($home.'/.transmissionDisable')
            && pmssTransmissionSettingsPrepare($home) && pmssTransmissionLaunch($home);
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
