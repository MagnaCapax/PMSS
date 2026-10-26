<?php
/** Watchdog convergence keeps refusal recovery and observations independent of launch commands. */
require_once __DIR__.'/qbittorrentRestoreAccount.php';
require_once __DIR__.'/qbittorrentThrottleWatchdog.php';

/** Observe once while running; while stopped repair even a previously refused config. */
function pmssQbittorrentWatchdogRunning(string $user, string $home, array $ops = []): bool
{
    $runningRead = $ops['running'] ?? static function (string $user): bool {
        return pmssTorrentClientLauncherLivenessPids($user, 'qbittorrent') !== [];
    };
    $account = $ops['account'] ?? 'pmssQbittorrentRestoreAsUser';
    $port = $ops['port'] ?? 'pmssQbittorrentPortEnsure';
    $auth = $ops['auth'] ?? 'pmssQbittorrentWebUiAuthEnsure';
    $managed = $ops['managed'] ?? static function (string $user, bool $running): void {
        pmssUserWatchdogApplyManagedConfigWhenStopped($user, $running, 'pmssQbittorrentApplyManagedConfig');
    };
    $running = $runningRead($user);
    // Account observation logs the refusal transition once, when it creates the marker.
    if ($running && !$account($user, $home, true)) return true;
    if (!$running) {
        if (!$account($user, $home)) return true;
        $port($user, $home);
    }
    if ($auth($user, $home)) $running = false;
    $managed($user, $running);
    // Release the marker only after the account checks what the next binary will actually read.
    if (!$running && !$account($user, $home, false, true)) return true;
    $throttle = $ops['throttle'] ?? static function (string $user, bool $running) use ($home): bool {
        return pmssQbittorrentThrottleWatchdog($user, $home, $running);
    };
    return $throttle($user, $running);
}
