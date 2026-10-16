<?php
/** Operator-side preparation for catalogued secured customer toggle apps. */

require_once __DIR__.'/watchdog.php';
require_once __DIR__.'/../portManager.php';
require_once __DIR__.'/../lighttpd/proxyFragments.php';
require_once __DIR__.'/../toggleAppCatalog.php';

/** Match the installed executable identity, including interpreter entry points. */
function pmssToggleAppProcessRunning(string $user, string $app): bool
{
    $catalog = pmssToggleAppCatalogRead();
    if (!pmssValidateUsername($user) || !isset($catalog[$app])) return false;
    $row = $catalog[$app];
    $script = $row['script'] ?? '';
    $binary = $row['exe'];
    $resolved = realpath($binary);
    if ($resolved === false) return false;
    $mountNamespace = @readlink('/proc/self/ns/mnt');
    if ($mountNamespace === false) return false;
    $pids = pmssUserWatchdogProcessPids($user, $row['processNeedle'], ['full' => $row['processFull']]);
    foreach ($pids as $pid) {
        if (@readlink('/proc/'.$pid.'/ns/mnt') !== $mountNamespace) continue;
        if (@readlink('/proc/'.$pid.'/exe') !== $resolved) continue;
        if ($script === '') return true;
        $command = @file_get_contents('/proc/'.$pid.'/cmdline', false, null, 0, 4096);
        $args = is_string($command) ? explode("\0", $command) : [];
        if (isset($args[1]) && realpath($args[1]) === realpath($script)) return true;
    }
    return false;
}

/** The port reservation stays outside home; all home writes run as the account. */
function pmssToggleAppPrepare(string $user, string $app): bool
{
    $catalog = pmssToggleAppCatalogRead();
    if (!pmssValidateUsername($user) || !isset($catalog[$app])) return false;
    $port = pmssPortManagerAssignServicePort($user, $app);
    if ($port === null) return false;
    $fragment = pmssLighttpdManagedProxyFragment($app, $user, $port);
    if ($fragment === '') return false;
    $peerPort = !empty($catalog[$app]['extraPortServices'])
        ? pmssPortManagerAssignServicePort($user, $catalog[$app]['extraPortServices'][0]) : 0;
    if ($peerPort === null) return false;
    $helper = '/home/'.$user.'/www/toggleAppRuntime.php';
    $inner = 'php '.escapeshellarg($helper).' bootstrap '.escapeshellarg($app).' '.escapeshellarg((string) $port).' '.escapeshellarg(base64_encode($fragment))
        .' '.escapeshellarg((string) $peerPort);
    $command = pmssBuildUserServiceShellCommand($user, $inner);
    @exec(pmssLockChildClosePrefix().$command.' >/dev/null 2>&1', $output, $rc);
    return $rc === 0;
}

/** The shell receives only paths and fixed flags, never the generated password. */
function pmssToggleAppLaunchCommand(array $row, string $config): string
{
    $tokens = array_map(static function (string $token) use ($config): string {
        return escapeshellarg($token === '{config}' ? $config : $token);
    }, $row['launch']);
    return ($row['workingDirectory'] !== '' ? 'cd '.escapeshellarg($row['workingDirectory']).' && ' : '')
        .'nohup '.implode(' ', $tokens);
}

/** Start only after the account-owned config and secured marker exist. */
function pmssToggleAppStartCommand(string $user, string $app): string
{
    $catalog = pmssToggleAppCatalogRead();
    if (!pmssValidateUsername($user) || !isset($catalog[$app])) return '';
    $row = $catalog[$app];
    $config = '/home/'.$user.'/'.$row['config'];
    $secured = '/home/'.$user.'/'.$row['securedMarker'];
    $enabled = '/home/'.$user.'/'.$row['enable'];
    $launch = pmssToggleAppLaunchCommand($row, $config);
    $conditions = 'test -s '.escapeshellarg($config).' && test -f '.escapeshellarg($secured)
        .' && test -f '.escapeshellarg($enabled);
    return pmssBuildUserServiceShellCommand($user, pmssToggleAppDetachedGroup($conditions, $launch));
}

/**
 * Run the checks and the launch as one backgrounded group with all streams detached.
 * A bare `a && b && cmd >/dev/null &` backgrounds a subshell that keeps the caller's
 * stdout open while cmd runs, so the watchdog's passthru never returns and the cron lock is held.
 */
function pmssToggleAppDetachedGroup(string $conditions, string $launch): string
{
    return '( umask 077; '.$conditions.' && '.$launch.' ) </dev/null >/dev/null 2>&1 &';
}
