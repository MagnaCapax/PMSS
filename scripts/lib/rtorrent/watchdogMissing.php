<?php
/** Missing-process recovery, retaining executor-specific grace. @license GPL-3.0-only */

/**
 * Handle absent rTorrent processes while preserving executor-specific grace.
 *
 * @param int[] $executorAllPids
 * @param array<string,string> $state
 */
function pmssCheckRtorrentHandleMissingProcess(
    string $user,
    string $home,
    bool $executorPresent,
    array $executorAllPids,
    array $state,
    callable $logCallback,
    bool $debug,
    int $missingGrace,
    int $startFailureSessionReset,
    int $startFailureEscalate
): void {
    $socketPath = rtorrentScgiSocketPath($user);
    pmssCheckRtorrentCleanupStaleSocket($user, $socketPath, $state['unresponsive'], $debug);

    if (!$executorPresent) {
        if (is_file($state['startMarker'])) {
            $persistentFailureCount = rtorrentProcessCheckFailureCountState($state['startFailure'], $startFailureEscalate)['count'];
            pmssCheckRtorrentLogBoth($user, "rTorrent still missing after start attempt; persistent failure {$persistentFailureCount}/{$startFailureEscalate}", $debug);

            if ($persistentFailureCount >= $startFailureSessionReset && !is_file($state['sessionReset'])) {
                if (rtorrentProcessResetSessionDirectory($home, $user, $logCallback)) {
                    rtorrentProcessWriteStateFile($state['sessionReset'], (string) time());
                    pmssCheckRtorrentLogBoth($user, 'persistent start failure recovery: reset session directory', $debug);
                }
            }

            if ($persistentFailureCount >= $startFailureEscalate) {
                pmssCheckRtorrentHandleEscalatedStartFailure(
                    $user,
                    $state,
                    $persistentFailureCount,
                    $missingGrace,
                    $logCallback,
                    $debug,
                    'rtorrentProcessStart'
                );
                return;
            }
        }

        if (!pmssCheckRtorrentStaggerAfterRecentReboot($user, 'rTorrent missing; ', $debug)) {
            pmssCheckRtorrentLogBoth($user, 'rTorrent missing; starting', $debug);
        }
        rtorrentProcessStart($user, $logCallback, $state['startMarker']);
        return;
    }

    $missingState = rtorrentProcessCheckStaleState($state['missing'], $missingGrace);
    if ($missingState['action'] === 'record') {
        pmssCheckRtorrentLog("Executor present but rTorrent missing for {$user}; observing", false, $debug);
        return;
    }
    if ($missingState['action'] === 'wait') {
        pmssCheckRtorrentLog("Executor present but rTorrent missing for {$user}; waiting (age={$missingState['age']}s)", false, $debug);
        return;
    }

    pmssCheckRtorrentRefreshExecutorFromSkel($user, $home, $debug);
    pmssCheckRtorrentLogBoth($user, "executor present but rTorrent missing for {$missingState['age']}s; restarting", $debug);
    pmssCheckRtorrentStaggerAfterRecentReboot($user, '', $debug);
    rtorrentProcessRestart($user, [], $executorAllPids, $logCallback, $debug);
    rtorrentProcessClearStaleState($state['missing']);
}
