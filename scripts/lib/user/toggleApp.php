<?php
/** Operator-side preparation for the two secured customer toggle apps. */

require_once __DIR__.'/watchdog.php';
require_once __DIR__.'/../portManager.php';
require_once __DIR__.'/../lighttpd/proxyFragments.php';

/** Match installed executable identity, including Copyparty's Python entry point. */
function pmssToggleAppProcessRunning(string $user, string $app): bool
{
    if (!pmssValidateUsername($user) || !in_array($app, ['slskd', 'copyparty'], true)) return false;
    $script = '/usr/local/bin/copyparty-sfx.py';
    $binary = $app === 'slskd' ? '/opt/slskd/slskd' : '/usr/bin/python3';
    $resolved = realpath($binary);
    if ($resolved === false) return false;
    $mountNamespace = @readlink('/proc/self/ns/mnt');
    if ($mountNamespace === false) return false;
    $pids = pmssUserWatchdogProcessPids($user, $app === 'slskd' ? 'slskd' : $script, ['full' => $app === 'copyparty']);
    foreach ($pids as $pid) {
        if (@readlink('/proc/'.$pid.'/ns/mnt') !== $mountNamespace) continue;
        if (@readlink('/proc/'.$pid.'/exe') !== $resolved) continue;
        if ($app === 'slskd') return true;
        $command = @file_get_contents('/proc/'.$pid.'/cmdline', false, null, 0, 4096);
        $args = is_string($command) ? explode("\0", $command) : [];
        if (isset($args[1]) && realpath($args[1]) === realpath($script)) return true;
    }
    return false;
}

/** The port reservation stays outside home; all home writes run as the account. */
function pmssToggleAppPrepare(string $user, string $app): bool
{
    if (!pmssValidateUsername($user) || !in_array($app, ['slskd', 'copyparty'], true)) return false;
    $port = pmssPortManagerAssignServicePort($user, $app);
    if ($port === null) return false;
    $fragment = pmssLighttpdManagedProxyFragment($app, $user, $port);
    if ($fragment === '') return false;
    // slskd's Soulseek listener needs its own reachable port; the upstream default collides between accounts.
    $peerPort = $app === 'slskd' ? pmssPortManagerAssignServicePort($user, 'slskd-peer') : 0;
    if ($peerPort === null) return false;
    $helper = '/home/'.$user.'/www/toggleAppRuntime.php';
    $inner = 'php '.escapeshellarg($helper).' bootstrap '.escapeshellarg($app).' '.escapeshellarg((string) $port).' '.escapeshellarg(base64_encode($fragment))
        .' '.escapeshellarg((string) $peerPort);
    $command = pmssBuildUserServiceShellCommand($user, $inner);
    @exec(pmssLockChildClosePrefix().$command.' >/dev/null 2>&1', $output, $rc);
    return $rc === 0;
}

/** The shell receives only paths and fixed flags, never the generated password. */
function pmssToggleAppStartCommand(string $user, string $app): string
{
    if (!pmssValidateUsername($user) || !in_array($app, ['slskd', 'copyparty'], true)) return '';
    $config = '/home/'.$user.'/.config/'.$app.'/'.($app === 'slskd' ? 'slskd.yml' : 'copyparty.conf');
    $secured = '/home/'.$user.'/.pmss/apps/'.$app.'.secured';
    $enabled = '/home/'.$user.'/.'.$app.'Enable';
    $binary = $app === 'slskd' ? '/opt/slskd/slskd' : '/usr/local/bin/copyparty-sfx.py';
    $launch = $app === 'slskd'
        ? 'cd /opt/slskd && nohup '.escapeshellarg($binary).' --config '.escapeshellarg($config)
        : 'nohup python3 '.escapeshellarg($binary).' -c '.escapeshellarg($config);
    $inner = 'test -s '.escapeshellarg($config).' && test -f '.escapeshellarg($secured)
        .' && test -f '.escapeshellarg($enabled).' && '.$launch.' >/dev/null 2>&1 &';
    return pmssBuildUserServiceShellCommand($user, $inner);
}
