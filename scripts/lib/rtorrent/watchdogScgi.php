<?php
/** Live-process SCGI grace, wedge policy and recovery. @license GPL-3.0-only */
/** Legacy grace/decision helpers: @copyright 2010-2025 Magna Capax Finland Oy @license Proprietary */

/**
 * Return the SCGI grace period, extending it after recent restart markers.
 *
 * @return array{grace:int,restartAge:int}
 */
function rtorrentProcessUnresponsiveGraceState(string $restartMarker, int $baseGrace, ?int $now = null): array
{
    $now = $now ?? time();
    $restartTs = is_file($restartMarker) ? (int) trim((string) @file_get_contents($restartMarker)) : 0;
    $restartAge = $restartTs > 0 ? max(0, $now - $restartTs) : 0;
    $grace = $baseGrace;
    if ($restartAge > 0 && $restartAge < 7200) {
        $grace = max($baseGrace, 600);
    } elseif ($restartAge > 0 && $restartAge < 14400) {
        $grace = max($baseGrace, 1200);
    }

    return ['grace' => $grace, 'restartAge' => $restartAge];
}

/** Decide what to do with an alive but SCGI-unresponsive process.
 * @return array{action:string,message:string} action is extend_grace|observe_wedge|restart.
 */
function rtorrentProcessScgiUnresponsiveDecision(
    array $rtorrentPids,
    array $processStates,
    ?array $queueSnapshot,
    string $wedgeStateFile,
    int $wedgeCycles
): array {
    $pidsText = implode(',', rtorrentProcessNormalizePids($rtorrentPids));
    if ($processStates === []) {
        return ['action' => 'extend_grace', 'message' => 'SCGI unresponsive but rtorrent process state is unavailable (pids='.$pidsText.'); extending grace'];
    }

    if (rtorrentProcessStatesHaveUninterruptibleIo($processStates)) {
        return ['action' => 'extend_grace', 'message' => 'SCGI unresponsive but rtorrent is in uninterruptible I/O state (pids='.$pidsText.'); extending grace'];
    }

    $queueText = $queueSnapshot === null
        ? 'queue=unavailable'
        : 'recvQ='.(int) $queueSnapshot['recvQ'].' sendQ='.(int) $queueSnapshot['sendQ'];
    if ($queueSnapshot === null || !rtorrentScgiSocketQueueSaturated($queueSnapshot)) {
        return ['action' => 'extend_grace', 'message' => 'SCGI unresponsive but rtorrent still alive (pids='.$pidsText.'; '.$queueText.'); extending grace'];
    }

    $wedgeState = rtorrentProcessCheckFailureCountState($wedgeStateFile, $wedgeCycles);
    if (($wedgeState['action'] ?? '') !== 'stale') {
        return ['action' => 'observe_wedge', 'message' => 'SCGI accept queue saturated for rtorrent (pids='.$pidsText.'; '.$queueText.'; count='.(int) ($wedgeState['count'] ?? 0).'/'.$wedgeCycles.'); observing'];
    }

    return ['action' => 'restart', 'message' => 'SCGI accept queue saturated for consecutive checks (pids='.$pidsText.'; '.$queueText.'); restarting rtorrent'];
}

/**
 * Handle the SCGI health path once exactly one rTorrent process is present.
 *
 * @param int[] $executorAllPids
 * @param array<string,string> $state
 */
function pmssCheckRtorrentHandleAliveProcess(
    string $user,
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

    $restartMarker = rtorrentProcessRestartMarkerPath($user);
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
        // Both pending decisions refresh grace; only liveness grace resets wedge evidence.
        if ($decision['action'] === 'extend_grace' || $decision['action'] === 'observe_wedge') {
            rtorrentProcessWriteStateFile($state['unresponsive'], (string) time());
            if ($decision['action'] === 'extend_grace') rtorrentProcessClearStaleState($state['acceptQueueWedge']);
            pmssCheckRtorrentLogBoth($user, $decision['message'], $debug);
            return;
        }

        pmssCheckRtorrentLogBoth($user, $decision['message'], $debug);
    } else {
        pmssCheckRtorrentCleanupStaleSocket($user, $socketPath, $state['unresponsive'], $debug);
        pmssCheckRtorrentLogBoth($user, "SCGI unresponsive for {$unresponsiveState['age']}s; restarting rtorrent", $debug);
    }

    // One restart completion path serves confirmed wedges and processes lost during grace.
    rtorrentProcessRestart($user, $rtorrentPids, $executorAllPids, $logCallback, $debug);
    rtorrentProcessClearStaleState($state['unresponsive']);
    if (!empty($rtorrentPids)) rtorrentProcessClearStaleState($state['acceptQueueWedge']);
}
