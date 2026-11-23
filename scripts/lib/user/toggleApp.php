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
    if (pmssUserWatchdogBackoffActive($user, $app)) return false;
    $port = pmssPortManagerAssignServicePort($user, $app);
    if ($port === null) return false;
    $fragment = pmssLighttpdManagedProxyFragment($app, $user, $port);
    if ($fragment === '') return false;
    $peerPort = !empty($catalog[$app]['extraPortServices'])
        ? pmssPortManagerAssignServicePort($user, $catalog[$app]['extraPortServices'][0]) : 0;
    if ($peerPort === null) return false;
    $helper = '/home/'.$user.'/www/toggleAppRuntime.php';
    $inner = 'php '.escapeshellarg($helper).' bootstrap '.escapeshellarg($app).' '.escapeshellarg((string) $port).' '.escapeshellarg(base64_encode($fragment))
        .' '.escapeshellarg((string) $peerPort).' --restart-on-change';
    $command = pmssBuildUserServiceShellCommand($user, $inner);
    return pmssToggleAppBootstrapRun($command, $user, $app);
}

/** Bound the account bootstrap; a timeout defers further work for this app. */
function pmssToggleAppBootstrapRun(string $command, string $user, string $app): bool
{
    $result = pmssUserWatchdogCommandRun($command.' >/dev/null 2>&1', 15, $user, $app);
    return $result['rc'] === 0;
}

/** The shell receives only paths and fixed flags, never the generated password. */
function pmssToggleAppLaunchCommand(array $row, string $config, string $home): string
{
    $expand = static function (string $token) use ($config, $home): string {
        return str_replace(array('{config}', '{configDir}', '{home}'), array($config, dirname($config), $home), $token);
    };
    $tokens = array_map(static function (string $token) use ($expand): string {
        return escapeshellarg($expand($token));
    }, $row['launch']);
    // The launch otherwise inherits root's working directory, which the account cannot read.
    $cd = $row['workingDirectory'] !== '' ? 'cd '.escapeshellarg($expand($row['workingDirectory'])).' && ' : '';
    $sources = '';
    if (!empty($row['ownerEnv'])) {
        // Only the account shell reads owner settings; malformed or non-private files are optional.
        $ownerEnv = escapeshellarg($expand('{home}/'.$row['ownerEnv']));
        $sources = 'if [ -f '.$ownerEnv.' ] && [ ! -L '.$ownerEnv.' ]'
            .' && [ "$(stat -c %u:%a -- '.$ownerEnv.')" = "$(id -u):600" ]'
            .' && /bin/sh -n '.$ownerEnv.' >/dev/null 2>&1; then . '.$ownerEnv.'; fi; ';
    }
    // Managed environment follows owner settings, so managed values keep precedence.
    if (!empty($row['sourceEnv'])) $sources .= '. '.escapeshellarg($config).'; ';
    if ($sources !== '') {
        $shell = 'set -a; '.$sources.'set +a; exec '.implode(' ', $tokens);
        return $cd.'nohup '.escapeshellarg('/bin/sh').' -c '.escapeshellarg($shell);
    }
    return $cd.'nohup '.implode(' ', $tokens);
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
    // Console output goes to an account-private log beside the app's other managed state files.
    $log = '/home/'.$user.'/'.dirname($row['securedMarker']).'/'.$app.'.log';
    $launch = pmssToggleAppLoggedLaunch(pmssToggleAppLaunchCommand($row, $config, '/home/'.$user), $log);
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

/**
 * Send stdout and stderr of a launch to a size-bounded log, run as the account under umask 077.
 * The writer drains the pipe until the app exits, so the app never meets a broken pipe, even when the log
 * cannot be written (a full quota). At the cap the log keeps its newest half, so the newest output is always
 * there and disk use stays near the cap.
 */
function pmssToggleAppLoggedLaunch(string $launch, string $log, int $cap = 1048576): string
{
    $file = escapeshellarg($log);
    $size = '$(stat -c %s '.$file.' 2>/dev/null || echo 0)';
    $trim = 'tail -c '.intdiv($cap, 2).' '.$file.' > '.escapeshellarg($log.'.tmp').' && mv -f '.escapeshellarg($log.'.tmp').' '.$file;
    $writer = 'while :; do s='.$size.'; [ "$s" -lt '.$cap.' ] || { '.$trim.'; s='.$size.'; }; '
        .'head -c '.max(1, intdiv($cap, 16)).' >> '.$file.'; [ "'.$size.'" -gt "$s" ] || break; done; cat >/dev/null';
    return '{ '.$launch.'; } 2>&1 | { '.$writer.'; }';
}
