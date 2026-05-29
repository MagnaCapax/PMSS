<?php
/**
 * Per-user cron watchdog helpers for process and service convergence.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

require_once dirname(__DIR__).'/runtime.php';
require_once __DIR__.'/log.php';
require_once __DIR__.'/selection.php';

/** Return true when watchdogs must avoid web-facing services for the user. */
function pmssUserWebRootUnavailable(string $username, string $homeRoot = '/home'): bool
{
    $homeDir = rtrim($homeRoot, '/').'/'.$username;
    return is_dir($homeDir.'/www-disabled') || !is_dir($homeDir.'/www');
}

/** @param array<int,string> $processNames */
function pmssUserWatchdogTerminateProcesses(string $username, array $processNames, int $signal = 9): void
{
    if (!pmssValidateUsername($username)) {
        return;
    }

    $signal = $signal === 15 ? 15 : 9;
    foreach ($processNames as $processName) {
        if (!is_string($processName) || $processName === '') {
            continue;
        }
        @passthru('killall -'.$signal.' -u '.escapeshellarg($username).' '.escapeshellarg($processName).' 2>/dev/null');
    }
}

/** @return int[] Normalized positive PIDs from a per-user pgrep probe. */
function pmssUserWatchdogProcessPids(string $username, string $processPattern, array $options = array(), ?int &$exitCode = null, ?array &$output = null): array
{
    if (!pmssValidateUsername($username) || $processPattern === '') {
        $exitCode = 1;
        $output = array();
        return array();
    }

    $command = 'pgrep -u '.escapeshellarg($username)
        .(!empty($options['full']) ? ' -f' : '')
        .(!empty($options['exact']) ? ' -x' : '')
        .' '.escapeshellarg($processPattern)
        .(!empty($options['captureStderr']) ? ' 2>&1' : ' 2>/dev/null');

    $matches = array();
    $rc = 1;
    @exec($command, $matches, $rc);
    $exitCode = $rc;
    $output = $matches;

    $pids = array();
    foreach ($matches as $rawPid) {
        $pid = trim((string) $rawPid);
        if ($pid !== '' && ctype_digit($pid)) {
            $pids[(int) $pid] = (int) $pid;
        }
    }
    return array_values($pids);
}

function pmssUserWatchdogProcessRunning(string $username, string $processName): bool
{
    $exitCode = 1;
    $pids = pmssUserWatchdogProcessPids($username, $processName, array(), $exitCode);
    return $exitCode === 0 && $pids !== array();
}

function pmssUserWatchdogSuCommand(string $username, string $innerCommand): string { return 'su '.escapeshellarg($username).' -c '.escapeshellarg($innerCommand); }

/** Read a watchdog-owned local TCP port, failing closed on malformed files. */
function pmssUserWatchdogLocalPortRead(string $path): ?int
{
    $raw = pmssReadRegularFileTrimmed($path);
    if ($raw === null || preg_match('/^[0-9]+$/', $raw) !== 1) {
        return null;
    }

    $port = (int) $raw;
    return ($port >= 1 && $port <= 65535) ? $port : null;
}

/** Return the oldest /proc start marker for exact process-name matches. */
function pmssUserWatchdogProcessStartTime(string $username, string $processName, string $procRoot = '/proc'): ?int
{
    $exitCode = 1;
    $pids = pmssUserWatchdogProcessPids($username, $processName, array('exact' => true), $exitCode);
    if ($exitCode !== 0 || $pids === array()) {
        return null;
    }

    $oldest = null;
    $procRoot = rtrim($procRoot, '/');
    foreach ($pids as $pid) {
        $mtime = @filemtime($procRoot.'/'.$pid);
        if (!is_int($mtime)) {
            continue;
        }
        $oldest = $oldest === null ? $mtime : min($oldest, $mtime);
    }

    return $oldest;
}

/** @param array<int,string> $processNames */
function pmssUserWatchdogRestartProcessesIf(string $username, bool $running, array $processNames, callable $restartNeeded, string $userLogMessage, int $signal = 9, ?callable $terminator = null): bool
{
    if (!pmssValidateUsername($username) || !$running || !$restartNeeded()) {
        return $running;
    }

    $terminator !== null ? $terminator() : pmssUserWatchdogTerminateProcesses($username, $processNames, $signal);
    pmssUserLog($username, $userLogMessage);
    return false;
}

function pmssUserWatchdogServiceSpec(string $processName, $command, string $userLogMessage, string $serviceLabel = ''): array { return $serviceLabel === '' ? ['processName' => $processName, 'command' => $command, 'userLogMessage' => $userLogMessage] : ['processName' => $processName, 'serviceLabel' => $serviceLabel, 'command' => $command, 'userLogMessage' => $userLogMessage]; }

/** @param array<int,array<string,mixed>> $serviceSpecs @param array<string,bool> $runningStates @return array<string,bool> */
function pmssUserWatchdogEnsureServices(string $username, array $serviceSpecs, array $runningStates = array()): array
{
    if (!pmssValidateUsername($username)) {
        return $runningStates;
    }

    foreach ($serviceSpecs as $serviceSpec) {
        $processName = isset($serviceSpec['processName']) ? (string) $serviceSpec['processName'] : '';
        if ($processName === '') {
            continue;
        }
        $command = $serviceSpec['command'] ?? '';
        is_callable($command) && $command = (string) $command($username);
        $running = isset($runningStates[$processName]) ? (bool) $runningStates[$processName] : pmssUserWatchdogProcessRunning($username, $processName);
        if (!$running && (string) $command !== '') {
            echo 'Start '.(string) ($serviceSpec['serviceLabel'] ?? $processName).' for user: '.$username."\n";
            passthru((string) $command);
            pmssUserLog($username, (string) ($serviceSpec['userLogMessage'] ?? ($processName.' start requested')));
        }
        $runningStates[$processName] = $running;
    }
    return $runningStates;
}

/** @param array<int,string> $processNames */
function pmssUserWatchdogHandleSuspended(string $username, array $processNames, string $userLogMessage, string $homeRoot = '/home'): bool
{
    if (!pmssValidateUsername($username) || !pmssUserWebRootUnavailable($username, $homeRoot)) {
        return false;
    }

    echo "User: {$username} is suspended\n";
    pmssUserWatchdogTerminateProcesses($username, $processNames, 9);
    pmssUserLog($username, $userLogMessage);
    return true;
}

/** @param array<int,string> $processNames @param array<int,array<string,mixed>> $serviceSpecs */
function pmssUserWatchdogRunService(string $heading, string $enableMarker, array $processNames, string $userLogMessage, array $serviceSpecs, ?callable $runningStateBuilder = null, ?string $optionalRequirePath = null, string $homeRoot = '/home', string $command = '/scripts/listUsers.php'): void
{
    $heading !== '' && print date('Y-m-d H:i:s') . ': Checking '.$heading." instances\n";
    $optionalRequirePath !== null && is_file($optionalRequirePath) && require_once $optionalRequirePath;
    if ($enableMarker === '') { return; }
    $homeRoot = rtrim($homeRoot === '/home' ? pmssResolvePathFromEnv('PMSS_HOME_DIR', '/home') : $homeRoot, '/');
    foreach (pmssListManagedUsers($command) as $username) {
        if (pmssUserWatchdogHandleSuspended($username, $processNames, $userLogMessage, $homeRoot) || !is_file($homeRoot.'/'.$username.'/.'.$enableMarker)) {
            continue;
        }
        pmssUserWatchdogEnsureServices($username, $serviceSpecs, $runningStateBuilder !== null ? (array) $runningStateBuilder($username) : array());
    }
}
