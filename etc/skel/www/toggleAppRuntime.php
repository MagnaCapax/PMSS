<?php
/** User-UID configuration and display state for secured toggle apps. */

/** Keep credentials and configs private even when the caller has a loose umask. */
function pmssToggleAppPrivateWrite(string $path, string $contents): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
    if (is_link($dir) || is_link($path)) return false;
    $tmp = @tempnam($dir, '.pmss-');
    if ($tmp === false) return false;
    $ok = @chmod($tmp, 0600) && @file_put_contents($tmp, $contents) === strlen($contents)
        && @rename($tmp, $path);
    if (!$ok) @unlink($tmp);
    return $ok;
}

/** Skip unchanged files: a rewrite bumps custom.d mtimes and the lighttpd watchdog restarts on that. */
function pmssToggleAppPrivateWriteIfChanged(string $path, string $contents): bool
{
    if (is_file($path) && !is_link($path) && (@fileperms($path) & 0777) === 0600
        && @file_get_contents($path) === $contents) return true;
    return pmssToggleAppPrivateWrite($path, $contents);
}

/**
 * Build the two upstream config formats from port-manager assignments. slskd's Soulseek
 * listener takes its own port on every address: peers must reach it, and the upstream
 * default port would collide between accounts on one host.
 */
function pmssToggleAppConfig(string $app, string $user, string $home, int $port, string $password, int $peerPort = 0): string
{
    if ($app === 'slskd') {
        return "web:\n  port: {$port}\n  ip_address: 127.0.0.1\n  https:\n    disabled: true\n  url_base: /user-{$user}/slskd\n  authentication:\n    disabled: false\n    username: {$user}\n    password: {$password}\nsoulseek:\n  listen_ip_address: 0.0.0.0\n  listen_port: {$peerPort}\n";
    }
    // Use the rightmost forwarded IP, away from client-prepended spoofed values.
    return "[global]\n  i: 127.0.0.1\n  p: {$port}\n  rp-loc: /user-{$user}/copyparty\n  xff-hdr: X-Forwarded-For\n  rproxy: -1\n\n[accounts]\n  {$user}: {$password}\n\n[/]\n  {$home}/data\n  accs:\n    A: {$user}\n";
}

/** Bootstrap runs as the account; the secured marker is the final write. */
function pmssToggleAppBootstrap(string $app, string $user, string $home, int $port, string $proxy, int $peerPort = 0): bool
{
    if (!in_array($app, ['slskd', 'copyparty'], true) || preg_match('/^[a-z][a-z0-9]{0,7}$/D', $user) !== 1
        || $port < 2000 || $port > 38000 || ($home !== '/home/'.$user && getenv('PMSS_TEST_MODE') !== '1')) return false;
    if ($app === 'slskd' && ($peerPort < 2000 || $peerPort > 38000 || $peerPort === $port)) return false;
    $base = $home.'/.pmss/apps';
    $config = $app === 'slskd' ? $home.'/.config/slskd/slskd.yml' : $home.'/.config/copyparty/copyparty.conf';
    $login = $base.'/'.$app.'.login';
    $secured = $base.'/'.$app.'.secured';
    $proxyPath = $home.'/.lighttpd/custom.d/pmss-'.$app.'.conf';
    if ($app === 'copyparty' && !is_dir($home.'/data') && !@mkdir($home.'/data', 0700, true)) return false;
    $saved = !is_link($login) && is_file($login) ? @file_get_contents($login) : false;
    $lines = is_string($saved) ? explode("\n", trim($saved)) : [];
    $password = count($lines) === 2 && $lines[0] === $user && preg_match('/^[a-f0-9]{48}$/D', $lines[1]) === 1 ? $lines[1] : '';
    $configValid = $password !== '' && !is_link($config) && is_file($config)
        && (@fileperms($config) & 0777) === 0600 && (@fileperms($login) & 0777) === 0600
        && @file_get_contents($config) === pmssToggleAppConfig($app, $user, $home, $port, $password, $peerPort);
    if (!is_file($secured) || !$configValid) {
        $password = bin2hex(random_bytes(24));
        if (!pmssToggleAppPrivateWrite($config, pmssToggleAppConfig($app, $user, $home, $port, $password, $peerPort))
            || !pmssToggleAppPrivateWrite($login, $user."\n".$password."\n")) return false;
    }
    // Yield to a pre-existing matching URL conditional instead of shadowing it.
    $directory = dirname($proxyPath);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true)) return false;
    $pattern = '^/user-'.$user.'/'.$app.'/';
    $siblings = @glob($directory.'/*.conf');
    if ($siblings === false) return false;
    foreach ($siblings as $sibling) {
        if ($sibling === $proxyPath) continue;
        if (is_link($sibling) || !is_file($sibling) || !is_readable($sibling)) return false;
        $other = @file_get_contents($sibling);
        if (!is_string($other)) return false;
        if (strpos($other, $pattern) !== false) {
            if (is_file($proxyPath) && !is_link($proxyPath)) @unlink($proxyPath);
            return false;
        }
    }
    if (!pmssToggleAppPrivateWriteIfChanged($proxyPath, $proxy)) return false;
    return pmssToggleAppPrivateWriteIfChanged($secured, "secured\n");
}

/** Publish only the fixed four-state vocabulary for the owner-facing panel. */
function pmssToggleAppStateWrite(string $app, string $state, string $home): bool
{
    if (!in_array($app, ['slskd', 'copyparty'], true)
        || !in_array($state, ['running', 'stopped', 'not secured', 'gave up'], true)) return false;
    return pmssToggleAppPrivateWrite($home.'/.pmss/apps/'.$app.'.state', $state."\n");
}

/** Atomic replacement changes the marker inode without root reading a home file. */
function pmssToggleAppRestartSignal(string $app, string $home): bool
{
    if (!in_array($app, ['slskd', 'copyparty'], true)) return false;
    return pmssToggleAppPrivateWrite($home.'/.pmss/apps/'.$app.'.restart', "restart\n");
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $entry = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid()) : false;
    $user = is_array($entry) ? (string) ($entry['name'] ?? '') : '';
    $home = '/home/'.$user;
    $action = (string) ($argv[1] ?? '');
    if ($action === 'state') $ok = pmssToggleAppStateWrite((string) ($argv[2] ?? ''), (string) ($argv[3] ?? ''), $home);
    elseif ($action === 'bootstrap') {
        $proxy = base64_decode((string) ($argv[4] ?? ''), true);
        $ok = is_string($proxy) && strlen($proxy) < 8192 && pmssToggleAppBootstrap((string) ($argv[2] ?? ''), $user, $home, (int) ($argv[3] ?? 0), $proxy, (int) ($argv[5] ?? 0));
    } else $ok = false;
    exit($ok ? 0 : 1);
}
