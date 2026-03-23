#!/usr/bin/env php
<?php
/**
 * checkRtorrent.php - Cron watchdog for per-user rTorrent instances.
 *
 * Monitors rTorrent health across all users and takes corrective action when
 * processes are missing, duplicated, or unresponsive. Runs from system cron
 * (typically every 2 minutes) and logs interventions to both stdout and
 * per-user log files.
 *
 * Health checks performed:
 * - Process existence: rtorrent and executor processes running
 * - Process count: detect and fix duplicate instances
 * - SCGI responsiveness: verify xmlrpc socket accepts connections and responds
 *
 * State tracking (uses /run/pmss/ state files):
 * - Executor-present-but-rtorrent-missing: 180s grace period
 * - SCGI unresponsive: 120s grace period (2 consecutive cron runs)
 * - Repeated start failures: session reset after 3 misses, escalation after 6
 *
 * Logging:
 * - Stdout: captured by cron for /var/log/pmss/cron/ or mail delivery
 * - Per-user: /var/log/pmss/users/<username>.log via pmssUserLog()
 *
 * Usage:
 *   /scripts/cron/checkRtorrent.php [--debug]
 *
 * @author    Aleksi Ursin <aleksi@magnacapax.fi>
 * @copyright 2010-2025 Magna Capax Finland Oy
 * @license   Proprietary
 */

require_once __DIR__.'/../lib/user/log.php';
require_once __DIR__.'/../lib/rtorrent/scgi.php';
require_once __DIR__.'/../lib/rtorrent/process.php';
require_once __DIR__.'/../lib/user/traffic.php';

$lifecycle = __DIR__.'/../lib/userLifecycle.php';
if (is_file($lifecycle)) {
    require_once $lifecycle;
}

$args = $argv ?? ($_SERVER['argv'] ?? []);
$debug = in_array('--debug', $args, true);

// Grace periods for transient conditions (seconds).
define('PMSS_RTORRENT_MISSING_GRACE', 180);
define('PMSS_RTORRENT_UNRESPONSIVE_GRACE', 120);
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
    if (function_exists('pmssUserLog')) {
        pmssUserLog($user, 'checkRtorrent: '.$message);
    }
}

// --- Main execution ---

// Avoid concurrent watchdog executions (cron overlap, manual runs).
$lockPath = (is_dir('/run/lock') ? '/run/lock' : '/tmp').'/pmss-checkRtorrent.lock';
$lockHandle = @fopen($lockPath, 'c');
if ($lockHandle === false || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    pmssCheckRtorrentLog('checkRtorrent already running; skipping', false, $debug);
    exit(0);
}

pmssCheckRtorrentLog('Checking rTorrent instances', false, $debug);

$usersOut = [];
$usersRc = 0;
@exec('/scripts/listUsers.php', $usersOut, $usersRc);
if ($usersRc !== 0) {
    pmssCheckRtorrentLog('ERROR: listUsers.php failed (rc='.$usersRc.'); aborting run', true, $debug);
    exit(1);
}

$changedConfig = [];
$stateDir = '/run/pmss';
if (!is_dir($stateDir)) {
    @mkdir($stateDir, 0755, true);
}

// Create logging callback for restart helper.
$logCallback = function (string $msg, bool $force) use ($debug): void {
    pmssCheckRtorrentLog($msg, $force, $debug);
};

foreach ($usersOut as $line) {
    $user = trim((string) $line);
    if ($user === '') {
        continue;
    }
    $userIsValid = function_exists('pmssValidateUsername')
        ? pmssValidateUsername($user)
        : (bool) preg_match('/^[a-z][a-z0-9]{0,7}$/', $user);
    if (!$userIsValid) {
        pmssCheckRtorrentLog("Skipping invalid username: {$user}", false, $debug);
        continue;
    }

    $home = '/home/'.$user;
    if (!is_dir($home)) {
        pmssCheckRtorrentLog("Skipping {$user}: home missing", false, $debug);
        continue;
    }

    // Suspended users: kill only rtorrent and executor — not all user processes (see GH#210).
    if (pmssUserWebRootUnavailable($user)) {
        $null = [];
        $rc = 0;
        @exec('killall -9 -u '.escapeshellarg($user).' rtorrent 2>/dev/null; killall -9 -u '.escapeshellarg($user).' .rtorrentExecute.php 2>/dev/null', $null, $rc);
        pmssCheckRtorrentLogBoth($user, "suspended; cleanup (killall rc={$rc})", $debug);
        continue;
    }

    // Only manage users with rTorrent config.
    if (!is_file($home.'/.rtorrent.rc')) {
        continue;
    }

    // Gather process state.
    $executor = rtorrentProcessExecutorPids($user);
    $executorPhpPids = $executor['php'];
    $executorScreenPids = $executor['screen'];
    $executorAllPids = $executor['all'];
    $rtorrentPids = rtorrentProcessPgrepExact($user, 'rtorrent');
    $executorPresent = !empty($executorPhpPids);

    // State file paths.
    $missingState = $stateDir.'/checkRtorrent-missing-'.$user.'.ts';
    $unresponsiveState = $stateDir.'/checkRtorrent-unresponsive-'.$user.'.ts';
    $startMarkerState = $stateDir.'/checkRtorrent-started-'.$user.'.ts';
    $startFailureState = $stateDir.'/checkRtorrent-start-failure-'.$user.'.count';
    $sessionResetState = $stateDir.'/checkRtorrent-session-reset-'.$user.'.ts';
    $escalationState = $stateDir.'/checkRtorrent-escalated-'.$user.'.flag';

    // Clear state if condition resolved.
    if (!empty($rtorrentPids) || !$executorPresent) {
        rtorrentProcessClearStaleState($missingState);
    }
    if (!empty($rtorrentPids) || $executorPresent) {
        rtorrentProcessClearStaleState($startMarkerState);
        rtorrentProcessClearStaleState($startFailureState);
        rtorrentProcessClearStaleState($sessionResetState);
        rtorrentProcessClearStaleState($escalationState);
    }

    // --- Anomaly: Multiple processes ---
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

    // --- Missing: No executor and no rTorrent ---
    if (!$executorPresent && empty($rtorrentPids)) {
        $socketPath = rtorrentScgiSocketPath($user);
        rtorrentProcessClearStaleState($unresponsiveState);
        if (file_exists($socketPath)) {
            pmssCheckRtorrentLogBoth($user, 'stale socket detected, process not running, cleaning up', $debug);
            $socketRemoved = @unlink($socketPath);
            if (!$socketRemoved && file_exists($socketPath)) {
                pmssCheckRtorrentLogBoth($user, "stale socket cleanup failed (socket={$socketPath})", $debug);
            }
        }

        $persistentFailureCount = 0;
        if (is_file($startMarkerState)) {
            $persistentFailureState = rtorrentProcessCheckFailureCountState(
                $startFailureState,
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
                && !is_file($sessionResetState)
            ) {
                if (rtorrentProcessResetSessionDirectory($home, $user, $logCallback)) {
                    @file_put_contents($sessionResetState, (string) time(), LOCK_EX);
                    pmssCheckRtorrentLogBoth(
                        $user,
                        'persistent start failure recovery: reset session directory',
                        $debug
                    );
                }
            }

            if ($persistentFailureCount >= PMSS_RTORRENT_START_FAILURE_ESCALATE) {
                @file_put_contents(
                    $escalationState,
                    json_encode(['timestamp' => time(), 'user' => $user, 'count' => $persistentFailureCount]),
                    LOCK_EX
                );
                pmssCheckRtorrentLogBoth(
                    $user,
                    'persistent start failure escalated; leaving retry disabled for external monitoring',
                    $debug
                );
                continue;
            }
        }

        // Solution 1: Stagger starts after reboot to prevent I/O storm.
        if (rtorrentProcessRecentReboot(600)) {
            $delay = rtorrentProcessStaggerDelay($user, 300);
            pmssCheckRtorrentLogBoth(
                $user,
                "rTorrent missing; staggering start by {$delay}s (post-reboot)",
                $debug
            );
            sleep($delay);
        } else {
            pmssCheckRtorrentLogBoth($user, 'rTorrent missing; starting', $debug);
        }

        $rc = 0;
        @passthru('/scripts/startRtorrent '.escapeshellarg($user), $rc);
        pmssCheckRtorrentLog("startRtorrent {$user} completed (rc={$rc})", true, $debug);
        @file_put_contents('/tmp/.pmss-rtorrent-restart-'.$user, (string) time(), LOCK_EX);
        @file_put_contents($startMarkerState, (string) time(), LOCK_EX);
        continue;
    }

    // --- Stale executor: Executor present but rTorrent missing ---
    if ($executorPresent && empty($rtorrentPids)) {
        $socketPath = rtorrentScgiSocketPath($user);
        rtorrentProcessClearStaleState($unresponsiveState);
        if (file_exists($socketPath)) {
            pmssCheckRtorrentLogBoth($user, 'stale socket detected, process not running, cleaning up', $debug);
            $socketRemoved = @unlink($socketPath);
            if (!$socketRemoved && file_exists($socketPath)) {
                pmssCheckRtorrentLogBoth($user, "stale socket cleanup failed (socket={$socketPath})", $debug);
            }
        }

        $state = rtorrentProcessCheckStaleState($missingState, PMSS_RTORRENT_MISSING_GRACE);

        if ($state['action'] === 'record') {
            pmssCheckRtorrentLog(
                "Executor present but rTorrent missing for {$user}; observing",
                false,
                $debug
            );
            continue;
        }

        if ($state['action'] === 'wait') {
            pmssCheckRtorrentLog(
                "Executor present but rTorrent missing for {$user}; waiting (age={$state['age']}s)",
                false,
                $debug
            );
            continue;
        }

        // Stale - restart.
        // Refresh executor script from skel if the deployed copy is outdated.
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
            "executor present but rTorrent missing for {$state['age']}s; restarting",
            $debug
        );

        // Solution 1: Stagger starts after reboot.
        if (rtorrentProcessRecentReboot(600)) {
            $delay = rtorrentProcessStaggerDelay($user, 300);
            pmssCheckRtorrentLogBoth(
                $user,
                "staggering start by {$delay}s (post-reboot)",
                $debug
            );
            sleep($delay);
        }

        rtorrentProcessRestart($user, [], $executorAllPids, $logCallback, $debug);
        rtorrentProcessClearStaleState($missingState);
        continue;
    }

    // --- SCGI Health Check: Process running, verify responsiveness ---
    if (!empty($rtorrentPids) && count($rtorrentPids) === 1) {
        $socketPath = rtorrentScgiSocketPath($user);
        $responsive = rtorrentScgiPing($socketPath, 5);

        if ($responsive) {
            rtorrentProcessClearStaleState($unresponsiveState);
            $throttle = pmssReadTorrentThrottle($user);
            if ($throttle !== null) {
                $throttleValue = $throttle > 0 ? $throttle : 0;
                if (!rtorrentScgiCallInt($socketPath, 'throttle.global_up.max_rate.set', $throttleValue, 5)) {
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

        // Re-check process liveness before treating the socket as a stuck SCGI endpoint.
        $rtorrentPids = rtorrentProcessPgrepExact($user, 'rtorrent');
        if (empty($rtorrentPids)) {
            rtorrentProcessClearStaleState($unresponsiveState);
            if (file_exists($socketPath)) {
                pmssCheckRtorrentLogBoth(
                    $user,
                    'stale socket detected, process not running, cleaning up',
                    $debug
                );
                $socketRemoved = @unlink($socketPath);
                if (!$socketRemoved && file_exists($socketPath)) {
                    pmssCheckRtorrentLogBoth($user, "stale socket cleanup failed (socket={$socketPath})", $debug);
                }
            }

            pmssCheckRtorrentLogBoth($user, 'rTorrent missing after SCGI probe; starting', $debug);
            $rc = 0;
            @passthru('/scripts/startRtorrent '.escapeshellarg($user), $rc);
            pmssCheckRtorrentLog("startRtorrent {$user} completed (rc={$rc})", true, $debug);
            @file_put_contents('/tmp/.pmss-rtorrent-restart-'.$user, (string) time(), LOCK_EX);
            @file_put_contents($startMarkerState, (string) time(), LOCK_EX);
            continue;
        }

        // Solution 2: Extend grace period if recently restarted (progressive backoff).
        $restartMarker = '/tmp/.pmss-rtorrent-restart-'.$user;
        $restartTs = is_file($restartMarker) ? (int) trim((string) @file_get_contents($restartMarker)) : 0;
        $restartAge = $restartTs > 0 ? max(0, time() - $restartTs) : 0;
        $effectiveGrace = PMSS_RTORRENT_UNRESPONSIVE_GRACE;
        if ($restartAge > 0 && $restartAge < 7200) {
            $effectiveGrace = max(PMSS_RTORRENT_UNRESPONSIVE_GRACE, 600);
        } elseif ($restartAge > 0 && $restartAge < 14400) {
            $effectiveGrace = max(PMSS_RTORRENT_UNRESPONSIVE_GRACE, 1200);
        }

        // Log extended grace for visibility.
        if ($effectiveGrace > PMSS_RTORRENT_UNRESPONSIVE_GRACE) {
            $restartTime = date('Y-m-d H:i:s', time() - $restartAge);
            pmssCheckRtorrentLog(
                "Extending SCGI grace to {$effectiveGrace}s for {$user} (recent restart at {$restartTime})",
                false,
                $debug
            );
        }

        // Unresponsive - check staleness.
        $state = rtorrentProcessCheckStaleState($unresponsiveState, $effectiveGrace);

        if ($state['action'] === 'record') {
            pmssCheckRtorrentLogBoth(
                $user,
                "SCGI unresponsive (socket={$socketPath}); observing",
                $debug
            );
            continue;
        }

        if ($state['action'] === 'wait') {
            pmssCheckRtorrentLog(
                "SCGI still unresponsive for {$user}; waiting (age={$state['age']}s, grace={$effectiveGrace}s)",
                false,
                $debug
            );
            continue;
        }

        // Stale - restart.
        pmssCheckRtorrentLogBoth(
            $user,
            "SCGI unresponsive for {$state['age']}s; restarting rtorrent",
            $debug
        );
        rtorrentProcessRestart($user, $rtorrentPids, $executorAllPids, $logCallback, $debug);
        rtorrentProcessClearStaleState($unresponsiveState);
        continue;
    }

    // --- Legacy: Check .rtorrent.rc ownership ---
    if (file_exists($home.'/.rtorrent.rc') && function_exists('posix_getpwuid')) {
        $owner = @posix_getpwuid(@fileowner($home.'/.rtorrent.rc'));
        if (is_array($owner) && isset($owner['name']) && $owner['name'] !== 'root') {
            $changedConfig[] = $user.' -> '.$owner['name'];
        }
    }
}

// Write changed config summary.
if (count($changedConfig) !== 0) {
    file_put_contents('/root/changedConfigs', implode("\n", $changedConfig));
} elseif (file_exists('/root/changedConfigs')) {
    unlink('/root/changedConfigs');
}
