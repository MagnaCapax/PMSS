#!/usr/bin/env php
<?php
/** Start a published, selected Transmission build after its settings converge. */

require_once __DIR__.'/../lib/user/watchdog.php';
require_once __DIR__.'/../lib/user/transmissionSettings.php';
require_once __DIR__.'/../lib/lighttpd/transmissionProxy.php';

$pmssCheckTransmissionLock = pmssCronLockAcquire('checkTransmissionInstances',
    'pmssCronLockSkipLog', 'pmssUserWatchdogLockAcquire');
if (!pmssTorrentClientsMarkerEnabled()) exit(0);
$users = pmssListManagedUsersFromResult(pmssListManagedUsersResult());
if ($users === null) exit(1);
foreach ($users as $user) {
    $home = '/home/'.$user;
    if (pmssUserWatchdogHandleSuspended($user, ['transmission-daemon'], 'Transmission stopped: suspended or moved')) continue;
    if (!pmssLighttpdTransmissionSelected($user, $home)) continue;
    // A process and its socket are checked before settings or launch are touched.
    if (pmssTorrentClientLauncherLivenessPids($user, 'transmission') !== []) continue;
    $socketPath = $home.'/.lighttpd/transmission.sock';
    if (pmssTransmissionSocketPathIsSymlink($socketPath)) continue;
    $socket = @stream_socket_client('unix://'.$socketPath, $errno, $error, 0.05);
    if (is_resource($socket)) { fclose($socket); continue; }
    if (!pmssTransmissionSettingsApply($user, $home)) continue;
    $command = 'cd '.escapeshellarg($home)
        .' && { [ ! -d ~/.config/pmss/torrentClients ] || { exec 9>>~/.config/pmss/torrentClients/transmission.lock; flock -n 9 || exit 0; }; php '
        .escapeshellarg($home.'/www/torrentClientsResolve.php')
        .' transmission --launch >/dev/null 2>&1; }';
    $output = [];
    $rc = 1;
    @exec(pmssBuildUserServiceShellCommand($user, $command), $output, $rc);
    if ($rc !== 0) pmssUserLog($user, 'Transmission start was not ready.');
}
