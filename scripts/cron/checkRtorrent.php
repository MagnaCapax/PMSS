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
require_once __DIR__.'/../lib/user/log.php';
require_once __DIR__.'/../lib/rtorrent/scgi.php';
require_once __DIR__.'/../lib/rtorrent/process.php';
require_once __DIR__.'/../lib/rtorrentConfig.php';
require_once __DIR__.'/../lib/user/userConfigStore.php';
require_once __DIR__.'/../lib/user/traffic.php';

require_once __DIR__.'/../lib/user/watchdog.php';

[$debug] = pmssCliArgvDebugSplit($argv ?? null);

// Grace periods for transient conditions (seconds).
define('PMSS_RTORRENT_MISSING_GRACE', 180);
define('PMSS_RTORRENT_UNRESPONSIVE_GRACE', 120);
define('PMSS_RTORRENT_ACCEPT_QUEUE_WEDGE_CYCLES', 3);
define('PMSS_RTORRENT_START_FAILURE_SESSION_RESET', 3);
define('PMSS_RTORRENT_START_FAILURE_ESCALATE', 6);

/**
 * Emit a log line to stdout (captured by cron redirection).
 *
 * @param string $message Log message.
 * @param bool   $force   Always log, even without debug mode.
 * @param bool   $debug   Debug mode enabled.
 *
 * @return void
 */
function pmssCheckRtorrentLog(string $message, bool $force = false, bool $debug = false): void
{
    if (!$force && !$debug) {
        return;
    }
    echo date('c').' '.$message."\n";
}

/**
 * Log to both cron output and per-user log file.
 *
 * @param string $user    Username for per-user log.
 * @param string $message Log message.
 * @param bool   $debug   Debug mode.
 *
 * @return void
 */
function pmssCheckRtorrentLogBoth(string $user, string $message, bool $debug): void
{
    pmssCheckRtorrentLog($message, true, $debug);
    pmssUserLog($user, 'checkRtorrent: '.$message);
}

// Clear a stale SCGI socket before restart or grace tracking.
function pmssCheckRtorrentCleanupStaleSocket(string $user, string $socketPath, string $unresponsiveState, bool $debug): void
{
    rtorrentProcessClearStaleState($unresponsiveState);
    if (!file_exists($socketPath)) {
        return;
    }

    pmssCheckRtorrentLogBoth($user, 'stale socket detected, process not running, cleaning up', $debug);
    $socketRemoved = @unlink($socketPath);
    if (!$socketRemoved && file_exists($socketPath)) {
        pmssCheckRtorrentLogBoth($user, "stale socket cleanup failed (socket={$socketPath})", $debug);
    }
}

// Delay starts shortly after reboot so many users do not hit storage at once.
function pmssCheckRtorrentStaggerAfterRecentReboot(string $user, string $messagePrefix, bool $debug): bool
{
    if (!rtorrentProcessRecentReboot(600)) {
        return false;
    }

    $delay = rtorrentProcessStaggerDelay($user, 300);
    pmssCheckRtorrentLogBoth($user, "{$messagePrefix}staggering start by {$delay}s (post-reboot)", $debug);
    sleep($delay);
    return true;
}

// Keep alive-but-unresponsive rTorrent handling in one conservative path.
function pmssCheckRtorrentExtendUnresponsiveGrace(
    string $user,
    string $message,
    string $unresponsiveState,
    string $acceptQueueWedgeState,
    bool $debug
): void {
    rtorrentProcessWriteStateFile($unresponsiveState, (string) time());
    rtorrentProcessClearStaleState($acceptQueueWedgeState);
    pmssCheckRtorrentLogBoth($user, $message, $debug);
}

/**
 * Rebuild a missing per-user rTorrent config from the canonical templates.
 *
 * @param string $user  Username whose config should be recreated.
 * @param string $home  User home directory.
 * @param bool   $debug Debug mode.
 *
 * @return bool True when the config exists after recovery.
 */
function pmssCheckRtorrentRecoverMissingConfig(string $user, string $home, bool $debug): bool
{
    if (!is_dir($home)) {
        return false;
    }

    pmssCheckRtorrentLogBoth($user, 'missing .rtorrent.rc detected; regenerating', $debug);

    $userConfigStore = new UserConfigStore();
    $payload = $userConfigStore->get($user);
    $payload = $userConfigStore->applyFallbacks($user, is_array($payload) ? $payload : []);
    $ramMiB = (int) ($payload['ramMiB'] ?? 0);
    if ($ramMiB <= 0) {
        pmssCheckRtorrentLogBoth($user, 'missing .rtorrent.rc recovery skipped (unable to resolve ramMiB)', $debug);
        return false;
    }

    $dhtDefault = @file_get_contents('/etc/seedbox/config/user.rtorrent.defaults.dht');
    $pexDefault = @file_get_contents('/etc/seedbox/config/user.rtorrent.defaults.pex');
    if (!is_string($dhtDefault) || !is_string($pexDefault)) {
        pmssCheckRtorrentLogBoth($user, 'missing .rtorrent.rc recovery failed (defaults unavailable)', $debug);
        return false;
    }

    $resourceFile = '/etc/seedbox/config/system.rtorrent.resources';
    $resources = is_file($resourceFile) ? (pmssReadSerializedArrayFile($resourceFile) ?? []) : [];

    $configInput = [
        'ram' => $ramMiB,
        'dht' => $dhtDefault,
        'pex' => $pexDefault,
        'uploadThrottle' => (($throttle = pmssReadTorrentThrottle($user)) === null) ? 0 : $throttle,
    ];
    if (isset($payload['rtorrentPort']) && is_numeric($payload['rtorrentPort']) && (int) $payload['rtorrentPort'] > 0) {
        $configInput['scgiPort'] = (int) $payload['rtorrentPort'];
    }

    try {
        $rtorrentConfig = new rtorrentConfig($resources);
        $configuration = $rtorrentConfig->createConfig($configInput);
        if (!$rtorrentConfig->writeConfig($user, $configuration['configFile'])) {
            pmssCheckRtorrentLogBoth($user, 'missing .rtorrent.rc recovery failed (write error)', $debug);
            return false;
        }
    } catch (Throwable $exception) {
        pmssCheckRtorrentLogBoth($user, 'missing .rtorrent.rc recovery failed: '.$exception->getMessage(), $debug);
        return false;
    }

    pmssCheckRtorrentLogBoth($user, 'missing .rtorrent.rc recovered', $debug);
    return true;
}

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
pmssDirEnsureExists($stateDir, 0755);

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

        $skelScript = '/etc/skel/.rtorrentExecute.php';
        $userScript = $home.'/.rtorrentExecute.php';
        if (is_file($skelScript) && is_file($userScript)
            && md5_file($skelScript) !== md5_file($userScript)
        ) {
            copy($skelScript, $userScript);
            @chown($userScript, $user);
            pmssCheckRtorrentLogBoth($user, 'refreshed stale executor from skel', $debug);
        }

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
            $throttle = pmssReadTorrentThrottle($user);
            if ($throttle !== null) {
                $throttleValue = $throttle > 0 ? $throttle : 0;
                if (rtorrentScgiCall($socketPath, 'throttle.global_up.max_rate.set', [$throttleValue], 5) === false) {
                    pmssCheckRtorrentLogBoth($user, 'failed to apply upload throttle', $debug);
                } else {
                    pmssCheckRtorrentLog(
                        "Applied upload throttle (up={$throttleValue} KiB/s) for {$user}",
                        false,
                        $debug
                    );
                }
            }
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

if (count($changedConfig) !== 0) {
    file_put_contents('/root/changedConfigs', implode("\n", $changedConfig));
} elseif (file_exists('/root/changedConfigs')) {
    unlink('/root/changedConfigs');
}
