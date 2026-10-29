<?php
/**
 * Per-user cron watchdog helpers for process and service convergence.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/runtime.php';
require_once __DIR__.'/restartSpace.php';
pmssRequireRelativeFiles(__DIR__, ['log.php', 'selection.php', 'serviceLaunch.php']);
require_once __DIR__.'/userConfigStore.php';
require_once __DIR__.'/watchdogTimeout.php';
pmssRequireRelativeFiles(__DIR__, ['watchdogProcess.php', 'watchdogPolicy.php']);

/** Return true when watchdogs must avoid web-facing services for the user. */
function pmssUserWebRootUnavailable(string $username, string $homeRoot = '/home'): bool
{
    $homeDir = rtrim($homeRoot, '/').'/'.$username;
    return is_dir($homeDir.'/www-disabled') || !is_dir($homeDir.'/www');
}

/** Park managed services for unavailable web roots and migrated old copies. */
function pmssUserServicesParked(string $username, string $homeRoot = '/home'): bool
{
    return pmssUserWebRootUnavailable($username, $homeRoot) || pmssUserMovedUntil($username) !== null;
}

function pmssUserWatchdogServiceSpec(string $processName, $command, string $userLogMessage, string $serviceLabel = '', array $options = []): array
{
    $spec = ['processName' => $processName] + ($serviceLabel === '' ? [] : ['serviceLabel' => $serviceLabel]) + ['command' => $command, 'userLogMessage' => $userLogMessage];
    foreach (['securedMarker', 'crashLoop'] as $key) {
        if (isset($options[$key])) $spec[$key] = $options[$key];
    }
    return $spec;
}

/** @param array<int,array<string,mixed>> $serviceSpecs @param array<string,bool> $runningStates @return array<string,bool> */
function pmssUserWatchdogEnsureServices(string $username, array $serviceSpecs, array $runningStates = array()): array
{
    if (!pmssValidateUsername($username)) {
        return $runningStates;
    }

    foreach ($serviceSpecs as $serviceSpec) {
        $processName = (string) ($serviceSpec['processName'] ?? '');
        if ($processName === '') {
            continue;
        }
        $running = (bool) ($runningStates[$processName] ?? pmssUserWatchdogProcessRunning($username, $processName));
        if (pmssUserWatchdogBackoffActive($username, $processName)) {
            $runningStates[$processName] = $running;
            continue;
        }
        $command = $serviceSpec['command'] ?? '';
        is_callable($command) && $command = (string) $command($username);
        $home = rtrim(pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home'), '/').'/'.$username;
        $allowed = pmssUserWatchdogGatedStartAllowed($username, $home, $serviceSpec, $running);
        if (!$running && $allowed && (string) $command !== ''
            && !pmssUserWatchdogBackoffActive($username, $processName)
            && pmssUserWatchdogGatedStartRecord($username, $serviceSpec)) {
            echo 'Start '.(string) ($serviceSpec['serviceLabel'] ?? $processName).' for user: '.$username."\n";
            pmssUserWatchdogCommandRun((string) $command, 90, $username, $processName);
            pmssUserLog($username, (string) ($serviceSpec['userLogMessage'] ?? ($processName.' start requested')));
        }
        $runningStates[$processName] = $running;
    }
    return $runningStates;
}

/** @param array<int,string> $processNames */
function pmssUserWatchdogHandleSuspended(string $username, array $processNames, string $userLogMessage, string $homeRoot = '/home'): bool
{
    if (!pmssValidateUsername($username) || !pmssUserServicesParked($username, $homeRoot)) {
        return false;
    }

    echo "User: {$username} is suspended or moved\n";
    pmssUserWatchdogTerminateProcesses($username, $processNames, 9);
    pmssUserLog($username, $userLogMessage);
    return true;
}

/** @param array<int,string> $processNames @param array<int,array<string,mixed>> $serviceSpecs */
function pmssUserWatchdogRunService(string $heading, string $enableMarker, array $processNames, string $userLogMessage, array $serviceSpecs, ?callable $runningStateBuilder = null, ?string $optionalRequirePath = null, string $homeRoot = '/home', string $command = '/scripts/listUsers.php', bool $reportMissingMarker = false): void
{
    $heading !== '' && print pmssLogFormatTimestampedLine('Checking '.$heading.' instances');
    $optionalRequirePath !== null && is_file($optionalRequirePath) && require_once $optionalRequirePath;
    if ($enableMarker === '') { return; }
    $homeRoot = rtrim($homeRoot === '/home' ? pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home') : $homeRoot, '/');
    $users = pmssListManagedUsersFromResult(pmssListManagedUsersResult($command));
    if ($users === null) {
        return;
    }

    $missingMarkerCount = 0;
    foreach ($users as $username) {
        if (pmssUserWatchdogHandleSuspended($username, $processNames, $userLogMessage, $homeRoot)) {
            continue;
        }
        if (!is_file($homeRoot.'/'.$username.'/.'.$enableMarker)) {
            $missingMarkerCount++;
            continue;
        }
        pmssUserWatchdogEnsureServices($username, $serviceSpecs, $runningStateBuilder !== null ? (array) $runningStateBuilder($username) : array());
    }
    if ($reportMissingMarker && $missingMarkerCount > 0) {
        echo $heading.': missing enable markers: '.$missingMarkerCount."\n";
    }
}
