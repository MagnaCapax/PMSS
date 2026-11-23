<?php
/**
 * Keep an opted-out account's panel route working while rTorrent stays stopped.
 *
 * The nginx route generator skips an account whose ~/.rtorrent.rc is missing and removes its
 * routes, so an account carrying the .rtorrentDisable marker could lose /user-<name>/ and the
 * marker branch of checkRtorrent.php never restored the file. This repairs the file and asks for
 * one account's route; it never starts rTorrent and never reads the account's files.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once __DIR__.'/watchdogState.php';

/** One repair attempt per account per window: a failing repair must not run every cron cycle. */
const PMSS_RTORRENT_OPTOUT_REPAIR_INTERVAL = 900;

if (!function_exists('pmssCheckRtorrentOptOutRepair')) {
    /**
     * Restore a missing .rtorrent.rc and the account's nginx route, at most once per window.
     *
     * The trigger is state, not an event: a route request that failed is retried in the next window
     * because the route file is still absent. A healthy account costs two stat calls and no state.
     *
     * @param callable $recoverConfig fn(string $user, string $home): bool restores the missing rc
     * @param callable $requestRoute  fn(string $user): bool regenerates and reloads that account's route
     * @return string healthy|throttled|state-unwritable|config-failed|config-recovered|route-requested|route-failed
     */
    function pmssCheckRtorrentOptOutRepair(
        string $user,
        string $home,
        string $stateDir,
        callable $recoverConfig,
        callable $requestRoute,
        ?int $now = null,
        string $routeDir = '/etc/nginx/users'
    ): string {
        $now = $now ?? time();
        $needsConfig = !is_file($home.'/.rtorrent.rc');
        if (!$needsConfig && is_file($routeDir.'/'.$user)) {
            return 'healthy';
        }

        $stateFile = rtrim($stateDir, '/').'/checkRtorrent-optout-repair-'.$user.'.ts';
        $last = pmssReadRegularFileInt($stateFile);
        if ($last > 0 && $now - $last < PMSS_RTORRENT_OPTOUT_REPAIR_INTERVAL) {
            return 'throttled';
        }
        // Without a recorded attempt the window cannot be enforced: fail closed rather than retry every cycle.
        if (!rtorrentProcessWriteStateFile($stateFile, (string) $now)) {
            return 'state-unwritable';
        }

        if ($needsConfig && !$recoverConfig($user, $home)) {
            return 'config-failed';
        }
        // The account can delete its own rc, but not the root-owned route: a present route needs no nginx reload.
        if (is_file($routeDir.'/'.$user)) {
            return 'config-recovered';
        }
        return $requestRoute($user) ? 'route-requested' : 'route-failed';
    }
}
