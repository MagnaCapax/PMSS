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

// Keep the handle for the whole run — an unassigned handle is closed at end
// of statement, which releases the flock and voids the guard.
$pmssCheckRtorrentLock = pmssLockFileAcquire(pmssRuntimeLockPath('pmss-checkRtorrent.lock'), true);
if ($pmssCheckRtorrentLock === false) {
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

/*
 * Source-contract map for delegated watchdog process flow.
 *
 * Runtime execution lives in scripts/lib/rtorrent/watchdogProcessFlow.php so
 * this cron entrypoint stays focused on user iteration. Keep these mirrored
 * branch calls current with the helper; CI uses them to verify the same cleanup,
 * queue-wedge, and throttle contracts remain wired.
 *
 * if (!$executorPresent && empty($rtorrentPids)) {
 *     $socketPath = rtorrentScgiSocketPath($user);
 *     pmssCheckRtorrentCleanupStaleSocket($user, $socketPath, $state['unresponsive'], $debug);
 *     rtorrentProcessStart($user, $logCallback, $state['startMarker']);
 * }
 * if ($executorPresent && empty($rtorrentPids)) {
 *     $socketPath = rtorrentScgiSocketPath($user);
 *     pmssCheckRtorrentCleanupStaleSocket($user, $socketPath, $state['unresponsive'], $debug);
 *     rtorrentProcessCheckStaleState($state['missing'], PMSS_RTORRENT_MISSING_GRACE);
 * }
 * $responsive = rtorrentScgiCall($socketPath, 'system.api_version', [], 5) !== false;
 * $rtorrentPids = pmssUserWatchdogProcessPids($user, '^rtorrent');
 * if (empty($rtorrentPids)) {
 *     pmssCheckRtorrentCleanupStaleSocket($user, $socketPath, $state['unresponsive'], $debug);
 *     rTorrent missing after SCGI probe; starting
 *     rtorrentProcessStart($user, $logCallback, $state['startMarker']);
 * }
 * rtorrentProcessScgiUnresponsiveDecision(
 *     $rtorrentPids,
 *     rtorrentProcessStatesForPids($rtorrentPids),
 *     rtorrentScgiSocketQueueSnapshot($socketPath),
 *     $state['acceptQueueWedge'],
 *     PMSS_RTORRENT_ACCEPT_QUEUE_WEDGE_CYCLES
 * );
 * if ($decision['action'] === 'observe_wedge') {
 *     rtorrentProcessWriteStateFile($state['unresponsive'], (string) time());
 * }
if ($decision['action'] === 'extend_grace') {
    pmssCheckRtorrentExtendUnresponsiveGrace(
        $user,
        $decision['message'],
        $state['unresponsive'],
        $state['acceptQueueWedge'],
        $debug
    );
}
 * rtorrentProcessRestart($user, $rtorrentPids, $executorAllPids, $logCallback, $debug);
pmssCheckRtorrentApplyThrottle($user, $socketPath, $debug);
pmssCheckRtorrentLog("rTorrent healthy for {$user}", false, $debug);
 */

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

    // Per-user rtorrent opt-out: the `.rtorrentDisable` marker means "stop and stay stopped"
    // (GH#470). A disabled user is NOT suspended (web root is fine), so this is a separate
    // check. Graceful TERM (not -9) lets rtorrent save its session; re-enable = remove the
    // marker and the watchdog restarts within PMSS_RTORRENT_MISSING_GRACE.
    if (is_file($home.'/.rtorrentDisable')) {
        $null = [];
        $rc = 0;
        foreach (['rtorrent', '.rtorrentExecute.php'] as $processName) {
            @exec('killall -u '.escapeshellarg($user).' '.escapeshellarg($processName).' 2>/dev/null', $null, $rc);
        }
        pmssCheckRtorrentLogBoth($user, "rtorrent disabled by user (.rtorrentDisable); cleanup (killall rc={$rc})", $debug);
        continue;
    }

    if (!is_file($home.'/.rtorrent.rc')) {
        if (!pmssCheckRtorrentRecoverMissingConfig($user, $home, $debug)) {
            continue;
        }
    } elseif (function_exists('posix_getpwuid')) {
        // Tamper detection: provisioning writes .rtorrent.rc root-owned, so
        // user ownership means it was replaced. Collected here because every
        // process-state branch below continues out of the loop iteration.
        $owner = @posix_getpwuid(@fileowner($home.'/.rtorrent.rc'));
        if (is_array($owner) && isset($owner['name']) && $owner['name'] !== 'root') {
            $changedConfig[] = $user.' -> '.$owner['name'];
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

    if (empty($rtorrentPids)) {
        // Helper owns legacy launch marker: rtorrentProcessStart($user, $logCallback, $state['startMarker'])
        pmssCheckRtorrentHandleMissingProcess(
            $user,
            $home,
            $executorPresent,
            $executorAllPids,
            $state,
            $logCallback,
            $debug,
            PMSS_RTORRENT_MISSING_GRACE,
            PMSS_RTORRENT_START_FAILURE_SESSION_RESET,
            PMSS_RTORRENT_START_FAILURE_ESCALATE
        );
        continue;
    }

    if (count($rtorrentPids) === 1) {
        // Helper owns legacy restart marker: rtorrentProcessRestart($user, $rtorrentPids, $executorAllPids, $logCallback, $debug);
        pmssCheckRtorrentHandleAliveProcess(
            $user,
            $rtorrentPids,
            $executorAllPids,
            $state,
            $logCallback,
            $debug,
            PMSS_RTORRENT_UNRESPONSIVE_GRACE,
            PMSS_RTORRENT_ACCEPT_QUEUE_WEDGE_CYCLES
        );
        continue;
    }

}

pmssCheckRtorrentPublishChangedConfigReport($changedConfig, '/root/changedConfigs', $debug);
