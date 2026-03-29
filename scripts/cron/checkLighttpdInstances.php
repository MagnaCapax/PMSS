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
require_once __DIR__.'/../lib/userLifecycle.php';

$argUserRaw = isset($argv[1]) ? trim((string)$argv[1]) : '';
if ($argUserRaw === '') {
    echo date('Y-m-d H:i:s') . ': Checking Lighttpd instances' . "\n";
}
$selection = pmssManagedUsersSelectFromCommand('/scripts/listUsers.php', $argUserRaw, array('lookupMode' => 'account', 'strictInput' => true));
if ($selection['exitCode'] !== 0) exit($selection['exitCode']);
$users = $selection['users'];
foreach($users AS $thisUser) {
    $homeDir = "/home/{$thisUser}";
    if (pmssUserWatchdogHandleSuspended($thisUser, ['lighttpd', 'php-cgi'], 'lighttpd stopped due to suspension')) {
        pmssLighttpdWatchdogDeleteErrorPage($thisUser);
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
        $socketError = true;
    } else {
        // Probe every expected php-cgi socket so partial worker crashes become
        // visible instead of leaving the user with intermittent 502 responses.
        foreach ($socketPaths as $socketPath) {
            $socket = fsockopen('unix://'.$socketPath, 0, $errno, $errstr, 5);
            if (!$socket or $errno or $errstr) {
                echo "Error when attempting to connect to socket {$socketPath}: {$errno}, {$errstr}\n";
                echo "php-cgi, for user: {$thisUser}. Killing lighttpd instances.\n";
                $socketError = true;
                break;
            }

            fclose($socket);
        }
    }
    $lighttpdRunningBeforeRestart = pmssUserWatchdogProcessRunning($thisUser, 'lighttpd');
    if ($socketError || !$lighttpdRunningBeforeRestart) {
        pmssLighttpdWatchdogWriteErrorPage(
            $thisUser,
            pmssLighttpdWatchdogDetectReason($thisUser, $homeDir, $homeDir.'/.lighttpd.conf', $socketError)
        );
    } else {
        pmssLighttpdWatchdogDeleteErrorPage($thisUser);
    }

    $lighttpdRunning = pmssUserWatchdogRestartProcessesIf($thisUser, $socketError || $lighttpdRunningBeforeRestart, ['lighttpd', 'php-cgi'], static function () use ($socketError): bool { return $socketError; }, 'lighttpd restart requested', 15, static function () use ($thisUser): void {
        echo "Killing (if any) lighttpd for user: {$thisUser}\n";
        pmssUserWatchdogTerminateProcesses($thisUser, ['lighttpd', 'php-cgi'], 15);
        sleep(5);
        pmssUserWatchdogTerminateProcesses($thisUser, ['lighttpd', 'php-cgi'], 9);
        usleep(50000);
    });
    pmssUserWatchdogEnsureServices($thisUser, [['processName' => 'lighttpd', 'command' => '/scripts/startLighttpd ' . $thisUser, 'userLogMessage' => 'lighttpd start requested']], ['lighttpd' => $lighttpdRunning]);
}
