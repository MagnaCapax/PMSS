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
require_once __DIR__.'/../lib/lighttpd/watchdogSocketProbe.php';
require_once __DIR__.'/../lib/user/userConfigStore.php';
require_once __DIR__.'/../lib/user/watchdog.php';

$argUserRaw = isset($argv[1]) ? trim((string)$argv[1]) : '';
if ($argUserRaw === '') {
    echo date('Y-m-d H:i:s') . ': Checking Lighttpd instances' . "\n";
}
$selection = pmssManagedUsersSelectFromCommand('/scripts/listUsers.php', $argUserRaw, array('lookupMode' => 'account', 'strictInput' => true));
if ($selection['exitCode'] !== 0) exit($selection['exitCode']);
$users = $selection['users'];
$homeRoot = pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home');
$watchdogWebRoot = pmssResolvePathFromEnv('PMSS_LIGHTTPD_WATCHDOG_WEB_ROOT', '/var/www');
foreach($users AS $thisUser) {
    $homeDir = rtrim($homeRoot, '/')."/{$thisUser}";
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

    if (!file_exists($homeDir.'/.lighttpd.conf') && is_dir($homeDir)) {
        echo "Config missing for user: {$thisUser} — generating\n";
        passthru('/scripts/util/userConfigLighttpd.php '.escapeshellarg($thisUser));
        pmssUserLog($thisUser, 'lighttpd config generated (missing config detected)');
    }

    $phpCgiRunning = pmssUserWatchdogProcessRunning($thisUser, 'php-cgi');
    $socketError = false;
    $socketPaths = pmssLighttpdWatchdogSocketPaths($homeDir, $homeDir.'/.lighttpd.conf');

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
        && pmssLighttpdConfigNewerThanProcess($homeDir, $homeDir.'/.lighttpd.conf', $lighttpdStartTime);
    if ($configChangedAfterStart) {
        echo "lighttpd config newer than running process, for user: {$thisUser}. Restarting lighttpd instances.\n";
        pmssUserLog($thisUser, 'lighttpd restart requested (config newer than process)');
    }

    if ($socketError || !$lighttpdRunningBeforeRestart) {
        pmssLighttpdWatchdogWriteErrorPage(
            $thisUser,
            pmssLighttpdWatchdogDetectReason($thisUser, $homeDir, $homeDir.'/.lighttpd.conf', $socketError),
            $watchdogWebRoot
        );
    } else {
        pmssLighttpdWatchdogDeleteErrorPage($thisUser, $watchdogWebRoot);
    }

    $restartRequired = $socketError || $configChangedAfterStart;
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
}
