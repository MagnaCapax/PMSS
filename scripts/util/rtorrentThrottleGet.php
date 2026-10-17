#!/usr/bin/env php
<?php
/**
 * Read a user's live rTorrent global rate limits over SCGI.
 *
 * Output values are bytes per second; zero means unlimited.
 *
 * @license GPL-3.0-only
 */

require_once __DIR__.'/../lib/user/identity.php';
require_once __DIR__.'/../lib/rtorrent/scgi.php';

/** Query both limits before emitting any result, so partial reads cannot look complete. */
function pmssRtorrentThrottleGetCli(array $argv, ?callable $scgiCall = null): int
{
    if (count($argv) !== 2 || !is_string($argv[1]) || !pmssUsernameIsValid($argv[1])) {
        fwrite(STDERR, "Usage: rtorrentThrottleGet.php USERNAME\n");
        return 1;
    }

    $socketPath = rtorrentScgiSocketPath($argv[1]);
    $scgiCall = $scgiCall ?? 'rtorrentScgiCall';
    $rates = [];
    foreach (['upload' => 'throttle.global_up.max_rate', 'download' => 'throttle.global_down.max_rate'] as $direction => $method) {
        $rate = $scgiCall($socketPath, $method);
        if (!is_int($rate) || $rate < 0) {
            fwrite(STDERR, "n/a: rTorrent SCGI rate unavailable\n");
            return 2;
        }
        $rates[$direction] = $rate;
    }

    foreach ($rates as $direction => $rate) {
        echo $direction.'_bytes_per_second='.$rate."\n";
    }
    return 0;
}

pmssRunCliEntrypointWithArgv(__FILE__, 'pmssRtorrentThrottleGetCli');
