#!/usr/bin/env php
<?php
/**
 * Cron task: check Lighttpd Instances.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */
require_once __DIR__.'/../lib/lighttpd/userConfigApply.php';
require_once __DIR__.'/../lib/lighttpd/watchdogErrorPage.php';
require_once __DIR__.'/../lib/lighttpd/watchdogNginxLogReader.php';
require_once __DIR__.'/../lib/lighttpd/watchdogSocketProbe.php';
require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/user/userConfigStore.php';
require_once __DIR__.'/../lib/user/watchdog.php';

$pmssCheckLighttpdLock = pmssUserWatchdogLockAcquire(pmssRuntimeLockPath('pmss-checkLighttpdInstances.lock'));
if ($pmssCheckLighttpdLock === false) {
    echo date('Y-m-d H:i:s').': checkLighttpdInstances already running; skipping' . "\n";
    exit(0);
}
$argUserRaw = isset($argv[1]) ? trim((string)$argv[1]) : '';
if ($argUserRaw === '') {
    echo date('Y-m-d H:i:s') . ': Checking Lighttpd instances' . "\n";
}
$selection = pmssManagedUsersSelectFromCommand('/scripts/listUsers.php', $argUserRaw, array('lookupMode' => 'account', 'strictInput' => true));
if ($selection['exitCode'] !== 0) exit($selection['exitCode']);
$users = $selection['users'];
$homeRoot = pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home');
$watchdogWebRoot = pmssResolvePathFromEnv('PMSS_LIGHTTPD_WATCHDOG_WEB_ROOT', '/var/www');
$nginxRecoveryActions = array();
if ($argUserRaw === '') {
    $portDir = rtrim(pmssResolvePathFromEnv('PMSS_PORT_MANAGER_DIR', '/etc/seedbox/runtime/ports'), '/');
    $nginxUsersByPort = array();
    foreach ($users as $username) {
        if (pmssUserWebRootUnavailable($username, $homeRoot) || !pmssUserLighttpdEnabled($username)) {
            continue;
        }
        $port = pmssReadRegularFileNetworkPort($portDir.'/lighttpd-'.$username);
        if ($port !== null) {
            $nginxUsersByPort[$port] = isset($nginxUsersByPort[$port]) ? '' : $username;
        }
    }
    $nginxUsersByPort = array_filter($nginxUsersByPort);
    $nginxRecoveryActions = pmssLighttpdWatchdogNginxActionsRead(
        pmssResolvePathFromEnv('PMSS_LIGHTTPD_WATCHDOG_NGINX_ACCESS_LOG', '/var/log/nginx/access.log'),
        pmssResolvePathFromEnv('PMSS_LIGHTTPD_WATCHDOG_NGINX_STATE', pmssRuntimeDir().'/checkLighttpdInstances-nginx.json'),
        $nginxUsersByPort
    );
}
foreach($users AS $thisUser) {
    $homeDir = rtrim($homeRoot, '/')."/{$thisUser}";
    $configPath = $homeDir.'/.lighttpd.conf';
    if (pmssUserWatchdogHandleSuspended($thisUser, ['lighttpd', 'php-cgi'], 'lighttpd stopped due to suspension', $homeRoot)) {
        pmssLighttpdWatchdogDeleteErrorPage($thisUser, $watchdogWebRoot);
        continue;
    }
    if (!pmssUserLighttpdEnabled($thisUser)) {
        echo "User {$thisUser}: lighttpd disabled by config; terminating web stack.\n";
        pmssUserWatchdogTerminateProcesses($thisUser, ['lighttpd', 'php-cgi'], 15);
        pmssLighttpdWatchdogDeleteErrorPage($thisUser, $watchdogWebRoot);
        continue;
    }

    if (!file_exists($configPath) && is_dir($homeDir)) {
        echo "Config missing for user: {$thisUser} — generating\n";
        passthru('/scripts/util/userConfigLighttpd.php '.escapeshellarg($thisUser));
        pmssUserLog($thisUser, 'lighttpd config generated (missing config detected)');
    }

    $nginxRecoveryAction = (string) ($nginxRecoveryActions[$thisUser] ?? '');
    if ($nginxRecoveryAction === 'reconfigure') {
        echo "Persistent nginx upstream 502s remain for user: {$thisUser}; regenerating lighttpd config before restart.\n";
        $reconfigureRc = runStep(
            "Regenerating lighttpd config for {$thisUser}",
            pmssBuildCommand('/scripts/util/userConfigLighttpd.php', [$thisUser])
        );
        pmssUserLog($thisUser, 'lighttpd config regeneration requested (persistent nginx upstream 502)');
        if ($reconfigureRc !== 0) {
            echo "Lighttpd config regeneration failed for user: {$thisUser}; continuing with guarded restart.\n";
        }
    } elseif ($nginxRecoveryAction === 'restart') {
        echo "Persistent nginx upstream 502s detected for user: {$thisUser}; restarting lighttpd.\n";
        pmssUserLog($thisUser, 'lighttpd restart requested (persistent nginx upstream 502)');
    }
    $nginxRecoveryRequired = $nginxRecoveryAction === 'restart' || $nginxRecoveryAction === 'reconfigure';

    $phpCgiRunning = pmssUserWatchdogProcessRunning($thisUser, 'php-cgi');
    $socketError = false;
    $socketPaths = pmssLighttpdWatchdogSocketPaths($homeDir, $configPath);

    if (!$phpCgiRunning) {
        echo "php-cgi not running, for user: {$thisUser}. Killing lighttpd instances.\n";
        pmssLighttpdWatchdogClearSocketFailure($thisUser);
        $socketError = true;
    } else {
        // Probe every expected php-cgi socket so partial worker crashes become
        // visible instead of leaving the user with intermittent 502 responses.
        $socketProbeFailed = false;
        foreach ($socketPaths as $socketPath) {
            $probeResult = pmssLighttpdWatchdogSocketProbeWithRetry($socketPath);
            if (!$probeResult['ok']) {
                $listeningSocketPaths = (int) $probeResult['errno'] === PMSS_LIGHTTPD_WATCHDOG_SOCKET_ECONNREFUSED
                    ? pmssLighttpdWatchdogListeningSocketPaths($homeDir)
                    : array();
                if (pmssLighttpdWatchdogSocketFailureIsStaleIndex(
                    (int) $probeResult['errno'],
                    $socketPaths,
                    $listeningSocketPaths
                )) {
                    echo "Configured php-cgi socket {$socketPath} refused the probe, but live listener coverage is healthy for user: {$thisUser}; skipping restart.\n";
                    pmssLighttpdWatchdogClearSocketFailure($thisUser);
                    break;
                }
                $socketProbeFailed = true;
                $failureState = pmssLighttpdWatchdogRecordSocketFailure($thisUser);
                echo "Error when attempting to connect to socket {$socketPath}: {$probeResult['errno']}, {$probeResult['errstr']} (after {$probeResult['attempts']} attempts)\n";
                echo "php-cgi socket probe failed for user: {$thisUser}; consecutive failures {$failureState['count']}/{$failureState['threshold']}.\n";
                if ($failureState['action'] === 'restart') {
                    echo "php-cgi, for user: {$thisUser}. Killing lighttpd instances.\n";
                    $socketError = true;
                } else {
                    echo "Deferring lighttpd restart for user: {$thisUser}; waiting for sustained socket failure.\n";
                }
                break;
            }
        }
        if (!$socketProbeFailed) {
            pmssLighttpdWatchdogClearSocketFailure($thisUser);
        }
    }
    $lighttpdRunningBeforeRestart = pmssUserWatchdogProcessRunning($thisUser, 'lighttpd');
    $lighttpdStartTime = $lighttpdRunningBeforeRestart
        ? pmssUserWatchdogProcessStartTime($thisUser, 'lighttpd')
        : null;
    $configChangedAfterStart = $lighttpdRunningBeforeRestart
        && pmssLighttpdConfigNewerThanProcess($homeDir, $configPath, $lighttpdStartTime);
    if ($configChangedAfterStart) {
        echo "lighttpd config newer than running process, for user: {$thisUser}. Restarting lighttpd instances.\n";
        pmssUserLog($thisUser, 'lighttpd restart requested (config newer than process)');
    }

    if ($socketError || !$lighttpdRunningBeforeRestart || $nginxRecoveryRequired) {
        $watchdogReason = pmssLighttpdWatchdogDetectReason($thisUser, $homeDir, $configPath, $socketError);
        pmssLighttpdWatchdogWriteErrorPage($thisUser, $watchdogReason, $watchdogWebRoot);
        pmssUserLog($thisUser, 'lighttpd watchdog: ' . $watchdogReason);
    } else {
        pmssLighttpdWatchdogDeleteErrorPage($thisUser, $watchdogWebRoot);
    }

    $restartRequired = $socketError || $configChangedAfterStart || $nginxRecoveryRequired;
    $lighttpdRunning = pmssUserWatchdogRestartProcessesIf(
        $thisUser,
        $socketError || $lighttpdRunningBeforeRestart,
        ['lighttpd', 'php-cgi'],
        static function () use ($restartRequired): bool {
            return $restartRequired;
        },
        'lighttpd restart requested',
        15,
        static function () use ($thisUser): void {
            echo "Killing (if any) lighttpd for user: {$thisUser}\n";
            pmssUserWatchdogTerminateProcesses($thisUser, ['lighttpd', 'php-cgi'], 15);
            sleep(5);
            pmssUserWatchdogTerminateProcesses($thisUser, ['lighttpd', 'php-cgi'], 9);
            usleep(50000);
        }
    );
    pmssUserWatchdogEnsureServices($thisUser, [pmssUserWatchdogServiceSpec('lighttpd', '/scripts/startLighttpd ' . $thisUser, 'lighttpd start requested')], ['lighttpd' => $lighttpdRunning]);
    if ($restartRequired) {
        $restartVerification = pmssLighttpdWatchdogRestartVerify($homeDir, $socketPaths);
        if ($restartVerification['status'] !== 'healthy') {
            $restartFailure = 'checkLighttpdInstances: '.$restartVerification['status'].' user='.$thisUser
                .' expected_listeners='.$restartVerification['expected'].' observed_listeners='.$restartVerification['observed']
                .' attempts='.$restartVerification['attempts'];
            echo $restartFailure."\n";
            pmssUserLog($thisUser, $restartFailure);
        }
    }
}
