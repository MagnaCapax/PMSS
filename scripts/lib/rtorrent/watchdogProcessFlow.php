<?php
/**
 * rTorrent watchdog process-state transitions.
 *
 * Loaded by watchdog.php after the shared logging and SCGI helpers are defined.
 * Keeps the cron entrypoint focused on user iteration while this module owns
 * missing-process and alive-but-unresponsive rTorrent state transitions.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

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
            $persistentFailureState = rtorrentProcessCheckFailureCountState($state['startFailure'], $startFailureEscalate);
            $persistentFailureCount = $persistentFailureState['count'];
            pmssCheckRtorrentLogBoth($user, "rTorrent still missing after start attempt; persistent failure {$persistentFailureCount}/{$startFailureEscalate}", $debug);

            if ($persistentFailureCount >= $startFailureSessionReset && !is_file($state['sessionReset'])) {
                if (rtorrentProcessResetSessionDirectory($home, $user, $logCallback)) {
                    rtorrentProcessWriteStateFile($state['sessionReset'], (string) time());
                    pmssCheckRtorrentLogBoth($user, 'persistent start failure recovery: reset session directory', $debug);
                }
            }

            if ($persistentFailureCount >= $startFailureEscalate) {
                rtorrentProcessWriteStateFile($state['escalation'], (string) json_encode(['timestamp' => time(), 'user' => $user, 'count' => $persistentFailureCount]));
                pmssCheckRtorrentLogBoth($user, 'persistent start failure escalated; leaving retry disabled for external monitoring', $debug);
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

/**
 * Handle the SCGI health path once exactly one rTorrent process is present.
 *
 * @param int[] $rtorrentPids
 * @param int[] $executorAllPids
 * @param array<string,string> $state
 */
function pmssCheckRtorrentHandleAliveProcess(
    string $user,
    array $rtorrentPids,
    array $executorAllPids,
    array $state,
    callable $logCallback,
    bool $debug,
    int $unresponsiveGrace,
    int $acceptQueueWedgeCycles
): void {
    $socketPath = rtorrentScgiSocketPath($user);
    if (rtorrentScgiCall($socketPath, 'system.api_version', [], 5) !== false) {
        rtorrentProcessClearStaleState($state['unresponsive']);
        rtorrentProcessClearStaleState($state['acceptQueueWedge']);
        pmssCheckRtorrentApplyThrottle($user, $socketPath, $debug);
        pmssCheckRtorrentLog("rTorrent healthy for {$user}", false, $debug);
        return;
    }

    $rtorrentPids = pmssUserWatchdogProcessPids($user, '^rtorrent');
    if (empty($rtorrentPids)) {
        pmssCheckRtorrentCleanupStaleSocket($user, $socketPath, $state['unresponsive'], $debug);
        pmssCheckRtorrentLogBoth($user, 'rTorrent missing after SCGI probe; starting', $debug);
        rtorrentProcessStart($user, $logCallback, $state['startMarker']);
        return;
    }

    $restartMarker = '/tmp/.pmss-rtorrent-restart-'.$user;
    $graceState = rtorrentProcessUnresponsiveGraceState($restartMarker, $unresponsiveGrace);
    $restartAge = $graceState['restartAge'];
    $effectiveGrace = $graceState['grace'];
    if ($effectiveGrace > $unresponsiveGrace) {
        $restartTime = date('Y-m-d H:i:s', time() - $restartAge);
        pmssCheckRtorrentLog("Extending SCGI grace to {$effectiveGrace}s for {$user} (recent restart at {$restartTime})", false, $debug);
    }

    $unresponsiveState = rtorrentProcessCheckStaleState($state['unresponsive'], $effectiveGrace);
    if ($unresponsiveState['action'] === 'record') {
        pmssCheckRtorrentLogBoth($user, "SCGI unresponsive (socket={$socketPath}); observing", $debug);
        return;
    }
    if ($unresponsiveState['action'] === 'wait') {
        pmssCheckRtorrentLog("SCGI still unresponsive for {$user}; waiting (age={$unresponsiveState['age']}s, grace={$effectiveGrace}s)", false, $debug);
        return;
    }

    $rtorrentPids = pmssUserWatchdogProcessPids($user, '^rtorrent');
    if (!empty($rtorrentPids)) {
        $decision = rtorrentProcessScgiUnresponsiveDecision(
            $rtorrentPids,
            rtorrentProcessStatesForPids($rtorrentPids),
            rtorrentScgiSocketQueueSnapshot($socketPath),
            $state['acceptQueueWedge'],
            $acceptQueueWedgeCycles
        );
        if ($decision['action'] === 'extend_grace') {
            pmssCheckRtorrentExtendUnresponsiveGrace($user, $decision['message'], $state['unresponsive'], $state['acceptQueueWedge'], $debug);
            return;
        }
        if ($decision['action'] === 'observe_wedge') {
            rtorrentProcessWriteStateFile($state['unresponsive'], (string) time());
            pmssCheckRtorrentLogBoth($user, $decision['message'], $debug);
            return;
        }

        pmssCheckRtorrentLogBoth($user, $decision['message'], $debug);
        rtorrentProcessRestart($user, $rtorrentPids, $executorAllPids, $logCallback, $debug);
        rtorrentProcessClearStaleState($state['unresponsive']);
        rtorrentProcessClearStaleState($state['acceptQueueWedge']);
        return;
    }

    pmssCheckRtorrentCleanupStaleSocket($user, $socketPath, $state['unresponsive'], $debug);
    pmssCheckRtorrentLogBoth($user, "SCGI unresponsive for {$unresponsiveState['age']}s; restarting rtorrent", $debug);
    rtorrentProcessRestart($user, [], $executorAllPids, $logCallback, $debug);
    rtorrentProcessClearStaleState($state['unresponsive']);
}
