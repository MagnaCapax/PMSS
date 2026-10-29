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
require_once __DIR__.'/../lib/lighttpd/hermesUiConverge.php';
require_once __DIR__.'/../lib/lighttpd/transmissionProxy.php';
require_once __DIR__.'/../lib/runtime.php';
require_once __DIR__.'/../lib/update/runtime/commands.php';
pmssRequireRelativeFiles(__DIR__.'/../lib', ['user/userConfigStore.php', 'user/watchdog.php']);

$argUserRaw = trim((string) ($argv[1] ?? ''));
$onBusy = $argUserRaw !== '' ? static function (string $message): int {
    pmssCronLockSkipLog($message);
    return PMSS_CRON_LOCK_BUSY_EXIT;
} : 'pmssCronLockSkipLog';
$pmssCheckLighttpdLock = pmssCronLockAcquire('checkLighttpdInstances', $onBusy, 'pmssUserWatchdogLockAcquire');
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
        if (pmssUserServicesParked($username, $homeRoot) || !pmssUserLighttpdEnabled($username)) {
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
    if (pmssUserWatchdogHandleSuspended($thisUser, ['lighttpd', 'php-cgi'], 'lighttpd stopped: suspended or moved', $homeRoot)) {
        pmssLighttpdWatchdogDeleteErrorPage($thisUser, $watchdogWebRoot);
        continue;
    }
    if (!pmssUserLighttpdEnabled($thisUser)) {
        echo "User {$thisUser}: lighttpd disabled by config; terminating web stack.\n";
        pmssUserWatchdogTerminateProcesses($thisUser, ['lighttpd', 'php-cgi'], 15);
        pmssLighttpdWatchdogDeleteErrorPage($thisUser, $watchdogWebRoot);
        continue;
    }
    pmssHermesUiConverge($thisUser, $homeDir);
    pmssLighttpdTransmissionRouteConverge($thisUser, $homeDir);
    if (pmssUserWatchdogBackoffActive($thisUser, 'lighttpd')) continue;

    if (!file_exists($configPath) && is_dir($homeDir)) {
        echo "Config missing for user: {$thisUser} — generating\n";
        $result = pmssUserWatchdogCommandRun(
            pmssBuildCommand('/scripts/util/userConfigLighttpd.php', [$thisUser]), 60, $thisUser, 'lighttpd'
        );
        if ($result['timed_out']) continue;
        pmssUserLog($thisUser, 'lighttpd config generated (missing config detected)');
    }

    // A successful customer Docker install requests one managed proxy rebuild.
    $dockerProxyRefresh = $homeDir.'/.lighttpd/.dockerClientProxyRefresh';
    if (is_file($dockerProxyRefresh) && !is_link($dockerProxyRefresh)) {
        $refresh = pmssUserWatchdogCommandRun(
            pmssBuildCommand('/scripts/util/userConfigLighttpd.php', [$thisUser]), 60, $thisUser, 'lighttpd'
        );
        if ($refresh['timed_out']) continue;
        if ($refresh['rc'] === 0) {
            @unlink($dockerProxyRefresh);
        }
    }

    $nginxRecoveryAction = (string) ($nginxRecoveryActions[$thisUser] ?? '');
    if ($nginxRecoveryAction === 'reconfigure') {
        echo "Persistent nginx upstream 502s remain for user: {$thisUser}; regenerating lighttpd config before restart.\n";
        $reconfigure = pmssUserWatchdogCommandRun(
            pmssBuildCommand('/scripts/util/userConfigLighttpd.php', [$thisUser]), 60, $thisUser, 'lighttpd'
        );
        if ($reconfigure['timed_out']) continue;
        pmssUserLog($thisUser, 'lighttpd config regeneration requested (persistent nginx upstream 502)');
        if ($reconfigure['rc'] !== 0) {
            echo "Lighttpd config regeneration failed for user: {$thisUser}; continuing with guarded restart.\n";
        }
    } elseif ($nginxRecoveryAction === 'restart') {
        echo "Persistent nginx upstream 502s detected for user: {$thisUser}; restarting lighttpd.\n";
        pmssUserLog($thisUser, 'lighttpd restart requested (persistent nginx upstream 502)');
    }
    $nginxRecoveryRequired = $nginxRecoveryAction === 'restart' || $nginxRecoveryAction === 'reconfigure';

    $phpCgiRunning = pmssUserWatchdogProcessRunning($thisUser, 'php-cgi');
    $socketPaths = pmssLighttpdWatchdogSocketPaths($homeDir, $configPath);
    $socketError = pmssLighttpdWatchdogSocketRestartRequired($thisUser, $homeDir, $socketPaths, $phpCgiRunning);
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
    // Keep a live daemon when its replacement cannot start on the home mount.
    if ($restartRequired && $lighttpdRunningBeforeRestart && !pmssAccountRestartSpaceAllows(
        $thisUser,
        $homeDir,
        'automatic lighttpd/php-cgi restart',
        static function (string $message): void { echo $message."\n"; }
    )) {
        $restartRequired = false;
        $socketError = false;
    }
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
