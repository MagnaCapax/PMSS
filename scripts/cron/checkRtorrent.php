#!/usr/bin/env php
<?php
/**
 * checkRtorrent.php - Cron watchdog for per-user rTorrent instances.
 *
 * Monitors rTorrent health across all users and fixes missing, duplicated, or
 * SCGI-unresponsive processes. Runs from root cron and logs interventions to
 * stdout plus per-user PMSS logs.
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 * @license   Proprietary
 */

require_once __DIR__.'/../lib/cli/optionParser.php';
require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/rtorrent/watchdog.php';

[$debug] = pmssCliArgvDebugSplit($argv ?? null);

// Grace periods for transient conditions (seconds).
define('PMSS_RTORRENT_MISSING_GRACE', 180);
define('PMSS_RTORRENT_UNRESPONSIVE_GRACE', 120);
define('PMSS_RTORRENT_ACCEPT_QUEUE_WEDGE_CYCLES', 3);
define('PMSS_RTORRENT_START_FAILURE_SESSION_RESET', 3);
define('PMSS_RTORRENT_START_FAILURE_ESCALATE', 6);

// --- Main execution ---

if (pmssLockFileAcquire(pmssRuntimeLockPath('pmss-checkRtorrent.lock'), true) === false) {
    pmssCheckRtorrentLog('checkRtorrent already running; skipping', false, $debug);
    exit(0);
}

pmssCheckRtorrentLog('Checking rTorrent instances', false, $debug);

$listUsersResult = pmssListManagedUsersResult('/scripts/listUsers.php');
if ((int) $listUsersResult['exitCode'] !== 0) {
    pmssCheckRtorrentLog('ERROR: listUsers.php failed (rc='.(int) $listUsersResult['exitCode'].'); aborting run', true, $debug);
    exit(1);
}
$users = $listUsersResult['users'];

$changedConfig = [];
$stateDir = '/run/pmss';
if (!pmssDirEnsureExists($stateDir, 0755)) {
    pmssCheckRtorrentLog('ERROR: failed to create runtime state directory: '.$stateDir, true, $debug);
    exit(1);
}

// Create logging callback for restart helper.
$logCallback = function (string $msg, bool $force) use ($debug): void {
    pmssCheckRtorrentLog($msg, $force, $debug);
};

foreach ($users as $user) {

    $home = '/home/'.$user;
    if (!is_dir($home)) {
        pmssCheckRtorrentLog("Skipping {$user}: home missing", false, $debug);
        continue;
    }

    // Suspended users: kill only rtorrent and executor — not all user processes (see GH#210).
    if (pmssUserWebRootUnavailable($user)) {
        $null = [];
        $rc = 0;
        foreach (['rtorrent', '.rtorrentExecute.php'] as $processName) {
            @exec('killall -9 -u '.escapeshellarg($user).' '.escapeshellarg($processName).' 2>/dev/null', $null, $rc);
        }
        pmssCheckRtorrentLogBoth($user, "suspended; cleanup (killall rc={$rc})", $debug);
        continue;
    }

    if (!is_file($home.'/.rtorrent.rc')) {
        if (!pmssCheckRtorrentRecoverMissingConfig($user, $home, $debug)) {
            continue;
        }
    }

    $executor = rtorrentProcessExecutorPids($user);
    $executorPhpPids = $executor['php'];
    $executorScreenPids = $executor['screen'];
    $executorAllPids = $executor['all'];
    $rtorrentPids = pmssUserWatchdogProcessPids($user, '^rtorrent');
    $executorPresent = !empty($executorPhpPids);

    $state = rtorrentProcessWatchdogStatePaths($stateDir, $user);
    rtorrentProcessClearResolvedWatchdogState($state, !empty($rtorrentPids), $executorPresent);

    if (count($executorPhpPids) > 1 || count($executorScreenPids) > 1 || count($rtorrentPids) > 1) {
        pmssCheckRtorrentLogBoth(
            $user,
            'anomaly detected (php_exec='.count($executorPhpPids)
                .' screen_exec='.count($executorScreenPids)
                .' rtorrent='.count($rtorrentPids).'); restarting',
            $debug
        );
        rtorrentProcessRestart($user, $rtorrentPids, $executorAllPids, $logCallback, $debug);
        continue;
    }

    if (!$executorPresent && empty($rtorrentPids)) {
        $socketPath = rtorrentScgiSocketPath($user);
        pmssCheckRtorrentCleanupStaleSocket($user, $socketPath, $state['unresponsive'], $debug);

        if (is_file($state['startMarker'])) {
            $persistentFailureState = rtorrentProcessCheckFailureCountState(
                $state['startFailure'],
                PMSS_RTORRENT_START_FAILURE_ESCALATE
            );
            $persistentFailureCount = $persistentFailureState['count'];

            pmssCheckRtorrentLogBoth(
                $user,
                "rTorrent still missing after start attempt; persistent failure {$persistentFailureCount}/"
                    .PMSS_RTORRENT_START_FAILURE_ESCALATE,
                $debug
            );

            if ($persistentFailureCount >= PMSS_RTORRENT_START_FAILURE_SESSION_RESET
                && !is_file($state['sessionReset'])
            ) {
                if (rtorrentProcessResetSessionDirectory($home, $user, $logCallback)) {
                    rtorrentProcessWriteStateFile($state['sessionReset'], (string) time());
                    pmssCheckRtorrentLogBoth(
                        $user,
                        'persistent start failure recovery: reset session directory',
                        $debug
                    );
                }
            }

            if ($persistentFailureCount >= PMSS_RTORRENT_START_FAILURE_ESCALATE) {
                rtorrentProcessWriteStateFile(
                    $state['escalation'],
                    (string) json_encode(['timestamp' => time(), 'user' => $user, 'count' => $persistentFailureCount])
                );
                pmssCheckRtorrentLogBoth(
                    $user,
                    'persistent start failure escalated; leaving retry disabled for external monitoring',
                    $debug
                );
                continue;
            }
        }

        if (!pmssCheckRtorrentStaggerAfterRecentReboot($user, 'rTorrent missing; ', $debug)) {
            pmssCheckRtorrentLogBoth($user, 'rTorrent missing; starting', $debug);
        }

        rtorrentProcessStart($user, $logCallback, $state['startMarker']);
        continue;
    }

    if ($executorPresent && empty($rtorrentPids)) {
        $socketPath = rtorrentScgiSocketPath($user);
        pmssCheckRtorrentCleanupStaleSocket($user, $socketPath, $state['unresponsive'], $debug);

        $missingState = rtorrentProcessCheckStaleState($state['missing'], PMSS_RTORRENT_MISSING_GRACE);

        if ($missingState['action'] === 'record') {
            pmssCheckRtorrentLog(
                "Executor present but rTorrent missing for {$user}; observing",
                false,
                $debug
            );
            continue;
        }

        if ($missingState['action'] === 'wait') {
            pmssCheckRtorrentLog(
                "Executor present but rTorrent missing for {$user}; waiting (age={$missingState['age']}s)",
                false,
                $debug
            );
            continue;
        }

        pmssCheckRtorrentRefreshExecutorFromSkel($user, $home, $debug);

        pmssCheckRtorrentLogBoth(
            $user,
            "executor present but rTorrent missing for {$missingState['age']}s; restarting",
            $debug
        );

        pmssCheckRtorrentStaggerAfterRecentReboot($user, '', $debug);

        rtorrentProcessRestart($user, [], $executorAllPids, $logCallback, $debug);
        rtorrentProcessClearStaleState($state['missing']);
        continue;
    }

    if (!empty($rtorrentPids) && count($rtorrentPids) === 1) {
        $socketPath = rtorrentScgiSocketPath($user);
        $responsive = rtorrentScgiCall($socketPath, 'system.api_version', [], 5) !== false;

        if ($responsive) {
            rtorrentProcessClearStaleState($state['unresponsive']);
            rtorrentProcessClearStaleState($state['acceptQueueWedge']);
            pmssCheckRtorrentApplyThrottle($user, $socketPath, $debug);
            pmssCheckRtorrentLog("rTorrent healthy for {$user}", false, $debug);
            continue;
        }

        $rtorrentPids = pmssUserWatchdogProcessPids($user, '^rtorrent');
        if (empty($rtorrentPids)) {
            pmssCheckRtorrentCleanupStaleSocket($user, $socketPath, $state['unresponsive'], $debug);

            pmssCheckRtorrentLogBoth($user, 'rTorrent missing after SCGI probe; starting', $debug);
            rtorrentProcessStart($user, $logCallback, $state['startMarker']);
            continue;
        }

        $restartMarker = '/tmp/.pmss-rtorrent-restart-'.$user;
        $graceState = rtorrentProcessUnresponsiveGraceState($restartMarker, PMSS_RTORRENT_UNRESPONSIVE_GRACE);
        $restartAge = $graceState['restartAge'];
        $effectiveGrace = $graceState['grace'];

        if ($effectiveGrace > PMSS_RTORRENT_UNRESPONSIVE_GRACE) {
            $restartTime = date('Y-m-d H:i:s', time() - $restartAge);
            pmssCheckRtorrentLog(
                "Extending SCGI grace to {$effectiveGrace}s for {$user} (recent restart at {$restartTime})",
                false,
                $debug
            );
        }

        $unresponsiveState = rtorrentProcessCheckStaleState($state['unresponsive'], $effectiveGrace);

        if ($unresponsiveState['action'] === 'record') {
            pmssCheckRtorrentLogBoth(
                $user,
                "SCGI unresponsive (socket={$socketPath}); observing",
                $debug
            );
            continue;
        }

        if ($unresponsiveState['action'] === 'wait') {
            pmssCheckRtorrentLog(
                "SCGI still unresponsive for {$user}; waiting (age={$unresponsiveState['age']}s, grace={$effectiveGrace}s)",
                false,
                $debug
            );
            continue;
        }

        $rtorrentPids = pmssUserWatchdogProcessPids($user, '^rtorrent');
        if (!empty($rtorrentPids)) {
            $processStates = rtorrentProcessStatesForPids($rtorrentPids);
            $queueSnapshot = rtorrentScgiSocketQueueSnapshot($socketPath);
            $decision = rtorrentProcessScgiUnresponsiveDecision(
                $rtorrentPids,
                $processStates,
                $queueSnapshot,
                $state['acceptQueueWedge'],
                PMSS_RTORRENT_ACCEPT_QUEUE_WEDGE_CYCLES
            );
            if ($decision['action'] === 'extend_grace') {
                pmssCheckRtorrentExtendUnresponsiveGrace(
                    $user,
                    $decision['message'],
                    $state['unresponsive'],
                    $state['acceptQueueWedge'],
                    $debug
                );
                continue;
            }
            if ($decision['action'] === 'observe_wedge') {
                rtorrentProcessWriteStateFile($state['unresponsive'], (string) time());
                pmssCheckRtorrentLogBoth(
                    $user,
                    $decision['message'],
                    $debug
                );
                continue;
            }

            pmssCheckRtorrentLogBoth(
                $user,
                $decision['message'],
                $debug
            );
            rtorrentProcessRestart($user, $rtorrentPids, $executorAllPids, $logCallback, $debug);
            rtorrentProcessClearStaleState($state['unresponsive']);
            rtorrentProcessClearStaleState($state['acceptQueueWedge']);
            continue;
        }

        pmssCheckRtorrentCleanupStaleSocket($user, $socketPath, $state['unresponsive'], $debug);
        pmssCheckRtorrentLogBoth(
            $user,
            "SCGI unresponsive for {$unresponsiveState['age']}s; restarting rtorrent",
            $debug
        );
        rtorrentProcessRestart($user, [], $executorAllPids, $logCallback, $debug);
        rtorrentProcessClearStaleState($state['unresponsive']);
        continue;
    }

    if (file_exists($home.'/.rtorrent.rc') && function_exists('posix_getpwuid')) {
        $owner = @posix_getpwuid(@fileowner($home.'/.rtorrent.rc'));
        if (is_array($owner) && isset($owner['name']) && $owner['name'] !== 'root') {
            $changedConfig[] = $user.' -> '.$owner['name'];
        }
    }
}

pmssCheckRtorrentPublishChangedConfigReport($changedConfig, '/root/changedConfigs', $debug);
