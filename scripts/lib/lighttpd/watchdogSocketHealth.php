<?php
/** Socket evidence to restart decision, preserving cron diagnostics and failure gates. @license GPL-3.0-only */

/** Check expected sockets in order and record at most one failure per watchdog cycle. */
function pmssLighttpdWatchdogSocketRestartRequired(string $thisUser, string $homeDir, array $socketPaths, bool $phpCgiRunning): bool
{
    if (!$phpCgiRunning) {
        echo "php-cgi not running, for user: {$thisUser}. Killing lighttpd instances.\n";
        pmssLighttpdWatchdogClearSocketFailure($thisUser);
        return true;
    }

    // Probe every expected worker so a partial pool crash is visible; stop at its first failure.
    foreach ($socketPaths as $socketPath) {
        $probeResult = pmssLighttpdWatchdogSocketProbeWithRetry($socketPath);
        if ($probeResult['ok']) continue;
        $listeningSocketPaths = (int) $probeResult['errno'] === PMSS_LIGHTTPD_WATCHDOG_SOCKET_ECONNREFUSED
            ? pmssLighttpdWatchdogListeningSocketSnapshot($homeDir)['paths'] : array();
        if (pmssLighttpdWatchdogSocketFailureIsStaleIndex((int) $probeResult['errno'], $socketPaths, $listeningSocketPaths)) {
            echo "Configured php-cgi socket {$socketPath} refused the probe, but live listener coverage is healthy for user: {$thisUser}; skipping restart.\n";
            pmssLighttpdWatchdogClearSocketFailure($thisUser);
            break;
        }

        $failureState = pmssLighttpdWatchdogRecordSocketFailure($thisUser);
        echo "Error when attempting to connect to socket {$socketPath}: {$probeResult['errno']}, {$probeResult['errstr']} (after {$probeResult['attempts']} attempts)\n";
        echo "php-cgi socket probe failed for user: {$thisUser}; consecutive failures {$failureState['count']}/{$failureState['threshold']}.\n";
        if ($failureState['action'] === 'restart') {
            echo "php-cgi, for user: {$thisUser}. Killing lighttpd instances.\n";
            return true;
        }
        echo "Deferring lighttpd restart for user: {$thisUser}; waiting for sustained socket failure.\n";
        return false;
    }

    pmssLighttpdWatchdogClearSocketFailure($thisUser);
    return false;
}
