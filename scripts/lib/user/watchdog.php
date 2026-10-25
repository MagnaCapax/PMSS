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

/** @param array<int,string> $processNames */
function pmssUserWatchdogTerminateProcesses(string $username, array $processNames, int $signal = 9): void
{
    if (!pmssValidateUsername($username)) {
        return;
    }

    $signal = $signal === 15 ? 15 : 9;
    foreach ($processNames as $processName) {
        if (!is_string($processName) || $processName === '' || $processName[0] === '-' || !pmssCommandBinaryNameIsSafe($processName)) {
            continue;
        }
        @passthru(pmssBuildCommand('killall', ['-'.$signal, '-u', $username, '--', $processName]).' 2>/dev/null');
    }
}

/** @return int[] Normalized positive PIDs; malformed patterns use the invalid-request result. */
function pmssUserWatchdogProcessPids(string $username, string $processPattern, array $options = array(), ?int &$exitCode = null, ?array &$output = null): array
{
    // Reject NUL before shell quoting can throw or truncate the process selector.
    if (!pmssValidateUsername($username) || $processPattern === '' || strpos($processPattern, "\0") !== false) {
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
        // Parse before selecting a process target; zero is not a PID.
        $pid = pmssUnsignedDecimalIntParse(trim((string) $rawPid));
        if ($pid !== null && $pid > 0) $pids[$pid] = $pid;
    }
    return array_values($pids);
}

function pmssUserWatchdogProcessRunning(string $username, string $processName): bool
{
    $exitCode = 1;
    $pids = pmssUserWatchdogProcessPids($username, $processName, array(), $exitCode);
    return $exitCode === 0 && $pids !== array();
}

/** Acquire a watchdog lock without leaking its descriptor into service daemons. */
function pmssUserWatchdogLockAcquire(string $path)
{
    $handle = pmssLockFileAcquire($path, true);
    if ($handle !== false) {
        pmssLockHandleExportChildCloseFds($handle);
    }
    return $handle;
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

/** Apply daemon-owned config only while stopped so live daemons cannot clobber it on exit. */
function pmssUserWatchdogApplyManagedConfigWhenStopped(string $username, bool $running, $configApplier): bool
{
    if (!pmssValidateUsername($username) || $running || !is_callable($configApplier)) {
        return false;
    }

    return (bool) $configApplier($username);
}

function pmssUserWatchdogServiceSpec(string $processName, $command, string $userLogMessage, string $serviceLabel = '', array $options = []): array
{
    $spec = $serviceLabel === '' ? ['processName' => $processName, 'command' => $command, 'userLogMessage' => $userLogMessage] : ['processName' => $processName, 'serviceLabel' => $serviceLabel, 'command' => $command, 'userLogMessage' => $userLogMessage];
    foreach (['securedMarker', 'crashLoop'] as $key) {
        if (isset($options[$key])) $spec[$key] = $options[$key];
    }
    return $spec;
}

/** Persist only opt-in crash history outside customer homes. */
function pmssUserWatchdogCrashState(string $username, string $app, ?array $next = null): array
{
    $empty = ['failures' => 0, 'pending' => false, 'restart' => ''];
    if (!pmssValidateUsername($username) || preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $app) !== 1) return ['storageError' => true];
    $dir = rtrim(pmssResolvePathFromEnv('PMSS_TOGGLE_APP_STATE_DIR', '/var/lib/pmss/toggle-apps'), '/');
    if ($dir === '' || $dir === '/home' || strpos($dir, '/home/') === 0
        || !pmssPathTargetIsSafe($dir, true) || (!is_dir($dir) && !@mkdir($dir, 0700, true)) || is_link($dir)) return ['storageError' => true];
    $path = $dir.'/'.$username.'-'.$app.'.json';
    if (!pmssPathTargetIsSafe($path, false, true)) return ['storageError' => true];
    if ($next !== null) {
        $bytes = json_encode($next);
        return is_string($bytes) && pmssFileWriteComplete($path, $bytes, LOCK_EX) ? $next : ['storageError' => true];
    }
    $old = is_file($path) ? @file_get_contents($path) : null;
    if ($old === false) return ['storageError' => true];
    if (($value = is_string($old) ? pmssJsonDecodeAssoc($old) : null) === null) return $empty;
    return ['failures' => is_int($value['failures'] ?? null) ? max(0, min(5, $value['failures'])) : 0,
        'pending' => ($value['pending'] ?? false) === true,
        'restart' => is_string($value['restart'] ?? null) ? $value['restart'] : ''];
}

/** Observe an atomically replaced customer restart marker through metadata only. */
function pmssUserWatchdogRestartToken(string $home, string $app): string
{
    $path = $home.'/.pmss/apps/'.$app.'.restart';
    clearstatcache(true, $path);
    $stat = @lstat($path);
    return is_array($stat) && ($stat['mode'] & 0170000) === 0100000
        ? $stat['ino'].':'.$stat['mtime'].':'.$stat['ctime'] : '';
}

/** Count only starts that were followed by an absent process on the next run. */
function pmssUserWatchdogCrashTransition(array $state, bool $running, string $token): array
{
    if ($token !== '' && $token !== ($state['restart'] ?? '')) return ['failures' => 0, 'pending' => false, 'restart' => $token];
    if ($running) return ['failures' => 0, 'pending' => false, 'restart' => $token];
    if (!empty($state['pending'])) {
        $state['failures'] = (int) ($state['failures'] ?? 0) + 1;
        $state['pending'] = false;
    }
    return $state;
}

/** Refuse path escapes and symlink markers before considering a gated start. */
function pmssUserWatchdogSecuredMarkerPresent(string $home, string $marker): bool
{
    return $marker !== '' && $marker[0] !== '/' && strpos($marker, '..') === false
        && preg_match('/^[A-Za-z0-9._\/-]+$/D', $marker) === 1
        && is_file($home.'/'.$marker) && !is_link($home.'/'.$marker);
}

/** Return whether an opt-in service may start and publish its display state as the user. */
function pmssUserWatchdogGatedStartAllowed(string $username, string $home, array $spec, bool $running): bool
{
    $app = (string) ($spec['crashLoop'] ?? '');
    $marker = (string) ($spec['securedMarker'] ?? '');
    if ($app === '' && $marker === '') return true;
    if ($marker !== '' && ($marker[0] === '/' || strpos($marker, '..') !== false || preg_match('/^[A-Za-z0-9._\/-]+$/D', $marker) !== 1)) return false;
    if ($app !== '' && preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $app) !== 1) return false;
    if ($app === '') {
        $secured = pmssUserWatchdogSecuredMarkerPresent($home, $marker);
        if (!$secured) pmssUserLog($username, $spec['processName'].' start skipped: not secured');
        return $secured && !$running;
    }
    $state = pmssUserWatchdogCrashState($username, $app);
    if (!empty($state['storageError'])) return false;
    $token = pmssUserWatchdogRestartToken($home, $app);
    $state = pmssUserWatchdogCrashTransition($state, $running, $token);
    $secured = $marker === '' || pmssUserWatchdogSecuredMarkerPresent($home, $marker);
    $display = !$secured ? 'not secured' : ($running ? 'running' : ($state['failures'] >= 5 ? 'gave up' : 'stopped'));
    if (!empty(pmssUserWatchdogCrashState($username, $app, $state)['storageError'])) return false;
    $helper = $home.'/www/toggleAppRuntime.php';
    $command = pmssBuildUserServiceShellCommand($username, 'php '.escapeshellarg($helper).' state '.escapeshellarg($app).' '.escapeshellarg($display));
    if (!pmssUserWatchdogBackoffActive($username, $app)) {
        pmssUserWatchdogCommandRun($command.' >/dev/null 2>&1', 15, $username, $app);
    }
    if (!$secured) pmssUserLog($username, $app.' start skipped: not secured');
    return $secured && !$running && $state['failures'] < 5;
}

/** Record a launch attempt only after the start command has been dispatched. */
function pmssUserWatchdogGatedStartRecord(string $username, array $spec): bool
{
    $app = (string) ($spec['crashLoop'] ?? '');
    if ($app === '') return true;
    $state = pmssUserWatchdogCrashState($username, $app);
    if (!empty($state['storageError'])) return false;
    $state['pending'] = true;
    return empty(pmssUserWatchdogCrashState($username, $app, $state)['storageError']);
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
        $service = $processName;
        if (!$running && $allowed && (string) $command !== ''
            && !pmssUserWatchdogBackoffActive($username, $service)
            && pmssUserWatchdogGatedStartRecord($username, $serviceSpec)) {
            echo 'Start '.(string) ($serviceSpec['serviceLabel'] ?? $processName).' for user: '.$username."\n";
            pmssUserWatchdogCommandRun((string) $command, 90, $username, $service);
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
