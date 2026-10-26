<?php
/** Compare before stopping; apply only after shutdown has saved its daemon-owned settings. */
require_once __DIR__.'/qbittorrentThrottleAccount.php';
require_once __DIR__.'/watchdog.php';

/** Return the watchdog running/start-blocked state, leaving service launch to its existing flow. */
function pmssQbittorrentThrottleWatchdog(string $user, string $home, bool $running, array $ops = []): bool
{
    $policy = $home.'/.torrentThrottle';
    $state = $home.'/.config/pmss/torrentClients/qbittorrent.throttleApplied';
    clearstatcache(true, $policy);
    clearstatcache(true, $state);
    // No-follow absence checks avoid an account subprocess for ordinary unthrottled users.
    if (@lstat($policy) === false && @lstat($state) === false) return $running;
    $throttleRead = $ops['read'] ?? 'pmssReadTorrentThrottle';
    $throttle = $throttleRead($user);
    $account = $ops['account'] ?? 'pmssQbittorrentThrottleAsUser';
    $runningRead = $ops['running'] ?? static function (string $user): bool {
        return pmssTorrentClientLauncherLivenessPids($user, 'qbittorrent') !== [];
    };
    if ($running) {
        $differs = $account($user, $home, $throttle, false);
        if ($differs !== true) return true;
        $stop = $ops['stop'] ?? static function (string $user): void { pmssTorrentClientNativeStop($user, 'qbittorrent', 15); };
        $stop($user);
    }
    // SIGTERM is asynchronous: defer both the stopped writer and launch until exit.
    $running = $runningRead($user);
    if ($running) return true;
    $applied = null;
    pmssUserWatchdogApplyManagedConfigWhenStopped($user, $running,
        static function (string $user) use ($home, $throttle, $account, &$applied): bool {
            $applied = $account($user, $home, $throttle, true);
            return $applied === true;
        });
    // Failed stop/lock/restore/write must never be followed by a start beside a live daemon.
    if ($applied === null) return true;
    if ($applied) {
        $log = $ops['log'] ?? 'pmssUserLog';
        $log($user, 'qbittorrent-nox upload throttle applied while stopped');
    }
    return false;
}
