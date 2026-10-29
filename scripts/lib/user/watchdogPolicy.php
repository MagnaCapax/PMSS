<?php
/** Crash history and opt-in start policy for user watchdogs. @license GPL-3.0-only */

/** Persist only opt-in crash history outside customer homes. */
function pmssUserWatchdogCrashState(string $username, string $app, ?array $next = null): array
{
    $empty = ['failures' => 0, 'pending' => false, 'restart' => ''];
    if (!pmssValidateUsername($username) || preg_match(PMSS_USER_WATCHDOG_SERVICE_PATTERN, $app) !== 1) return ['storageError' => true];
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
    if (($token !== '' && $token !== ($state['restart'] ?? '')) || $running) return ['failures' => 0, 'pending' => false, 'restart' => $token];
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
    if ($app !== '' && preg_match(PMSS_USER_WATCHDOG_SERVICE_PATTERN, $app) !== 1) return false;
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
