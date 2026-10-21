#!/usr/bin/env php
<?php
/**
 * checkDelugeInstances.php
 *
 * Cron helper that ensures each user with Deluge enabled has both the
 * daemon and web interface running. When either process is not found,
 * it is started under the user's account.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../lib/user/watchdog.php';
require_once __DIR__.'/../lib/user/delugeManagedConfig.php';
require_once __DIR__.'/../lib/user/delugePasswords.php';
require_once __DIR__.'/../lib/torrentClients/identity.php';

$pmssCheckDelugeLock = pmssCronLockAcquire('checkDelugeInstances', 'pmssCronLockSkipLog', 'pmssUserWatchdogLockAcquire');

pmssUserWatchdogRunService('Deluge', 'delugeEnable', ['deluged', 'deluge-web'], 'deluge stopped: suspended or moved', [
    pmssUserWatchdogServiceSpec('deluged', static function (string $thisUser): string {
        return pmssBuildUserServiceShellCommand($thisUser, "[ ! -d ~/.config/pmss/torrentClients ] || { exec 9>>~/.config/pmss/torrentClients/deluge.lock; flock -n 9 || exit 0; }; [ -L ~/bin/deluge/default ] && [ -f /etc/seedbox/config/torrentClients.enabled ] && (cd ~; php ~/www/torrentClientsResolve.php deluge --launch deluged >/dev/null 2>&1) 9>&- || (cd ~; deluged -l /home/{$thisUser}/.delugeLog -L info) 9>&-");
    }, 'deluged start requested'),
    pmssUserWatchdogServiceSpec('deluge-web', static function (string $thisUser): string {
        return pmssBuildUserServiceShellCommand($thisUser, "[ ! -d ~/.config/pmss/torrentClients ] || { exec 9>>~/.config/pmss/torrentClients/deluge.lock; flock -n 9 || exit 0; }; [ -L ~/bin/deluge/default ] && [ -f /etc/seedbox/config/torrentClients.enabled ] && (cd ~; php ~/www/torrentClientsResolve.php deluge --launch deluge-web >/dev/null 2>&1) 9>&- || (cd ~; deluge-web -l /home/{$thisUser}/.delugeWebLog -L info) 9>&-");
    }, 'deluge-web start requested'),
], function (string $thisUser): array {
    if (!pmssDelugeCredentialReadyForStart($thisUser)) {
        return ['deluged' => true, 'deluge-web' => true];
    }
    $running = pmssUserWatchdogProcessRunning($thisUser, 'deluged');
    pmssUserWatchdogApplyManagedConfigWhenStopped($thisUser, $running, 'pmssDelugeApplyManagedConfig');
    $delugedRunning = pmssUserWatchdogRestartProcessesIf($thisUser, $running, ['deluged', 'deluge-web'], static function () use ($thisUser): bool { return function_exists('pmssDelugeApplyUploadThrottle') && pmssDelugeApplyUploadThrottle($thisUser); }, 'deluge restarted to apply upload throttle', 9, static function () use ($thisUser): void {
        $daemon = pmssTorrentClientNativeStopPids($thisUser, 'deluge', ['storeOnly' => true]);
        $web = pmssTorrentClientNativeStopPids($thisUser, 'deluge', ['storeOnly' => true, 'processName' => 'deluge-web']);
        if ($daemon === [] && $web === []) { pmssUserWatchdogTerminateProcesses($thisUser, ['deluged', 'deluge-web'], 9); return; }
        pmssTorrentClientNativeStop($thisUser, 'deluge', 15, ['storeOnly' => true]);
        pmssTorrentClientNativeStop($thisUser, 'deluge', 15, ['storeOnly' => true, 'processName' => 'deluge-web']);
    });
    return ['deluged' => $delugedRunning];
}, __DIR__.'/../lib/user/deluge.php');
