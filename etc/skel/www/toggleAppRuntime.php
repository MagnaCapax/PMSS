<?php
/** User-UID configuration and display state for secured toggle apps. */
function pmssToggleAppCustomerCatalog(): array
{
    return require __DIR__.'/toggleAppCatalog.php';
}

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
 * Build upstream config formats from port-manager assignments. slskd's Soulseek
 * listener takes its own port on every address: peers must reach it, and the upstream
 * default port would collide between accounts on one host.
 */
function pmssToggleAppSlskdConfig(string $user, string $home, int $port, string $password, int $peerPort): array
{
    return array('.config/slskd/slskd.yml' => "web:\n  port: {$port}\n  ip_address: 127.0.0.1\n  https:\n    disabled: true\n  url_base: /user-{$user}/slskd\n  authentication:\n    disabled: false\n    username: {$user}\n    password: {$password}\nsoulseek:\n  listen_ip_address: 0.0.0.0\n  listen_port: {$peerPort}\n");
}

function pmssToggleAppCopypartyConfig(string $user, string $home, int $port, string $password, int $peerPort): array
{
    // Use the rightmost forwarded IP, away from client-prepended spoofed values.
    return array('.config/copyparty/copyparty.conf' => "[global]\n  i: 127.0.0.1\n  p: {$port}\n  rp-loc: /user-{$user}/copyparty\n  xff-hdr: X-Forwarded-For\n  rproxy: -1\n\n[accounts]\n  {$user}: {$password}\n\n[/]\n  {$home}/data\n  accs:\n    A: {$user}\n");
}

/** qui serves its own URL prefix and stores its account database beside this file. */
function pmssToggleAppQuiConfig(string $user, string $home, int $port, string $password, int $peerPort): array
{
    return array('.config/qui/config.toml' => "host = \"127.0.0.1\"\nport = {$port}\nbaseUrl = \"/user-{$user}/qui/\"\ncheckForUpdates = false\ndisableSelfUpdate = true\n");
}

/** Scryer reads its temporary admin password from a private file on every start. */
function pmssToggleAppScryerConfig(string $user, string $home, int $port, string $password, int $peerPort): array
{
    return array(
        '.config/scryer/scryer.env' => "SCRYER_BIND=127.0.0.1:{$port}\nSCRYER_BASE_PATH=/user-{$user}/scryer\nSCRYER_AUTH_ENABLED=true\nSCRYER_ADMIN_USERNAME={$user}\nSCRYER_ADMIN_PASSWORD_FILE={$home}/.pmss/apps/scryer.pw\nSCRYER_DISABLE_DEFAULT_ADMIN=true\n",
        '.pmss/apps/scryer.pw' => $password."\n",
    );
}

/** Feed qui's account CLI through stdin so the secret never appears in argv. */
function pmssToggleAppQuiAccountCommand(string $binary, string $configDir, string $user, string $password, string $action): array
{
    $command = escapeshellarg($binary).' '.escapeshellarg($action).' --config-dir '.escapeshellarg($configDir)
        .' --username '.escapeshellarg($user);
    $process = @proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($process)) return array(false, '');
    fwrite($pipes[0], $password."\n");
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return array(proc_close($process) === 0, $output);
}

/** An existing database may already contain qui's single account; rotate it then. */
function pmssToggleAppQuiAccountSet(string $binary, string $configDir, string $user, string $password): bool
{
    list($created, $output) = pmssToggleAppQuiAccountCommand($binary, $configDir, $user, $password, 'create-user');
    if ($created) return true;
    if (strpos($output, 'User account already exists. Only one user account is allowed.') === false) return false;
    list($changed) = pmssToggleAppQuiAccountCommand($binary, $configDir, $user, $password, 'change-password');
    return $changed;
}

/** Each renderer owns only its upstream format; the catalog chooses the renderer. */
function pmssToggleAppConfigs(string $app, string $user, string $home, int $port, string $password, int $peerPort): array
{
    $catalog = pmssToggleAppCustomerCatalog();
    if (!isset($catalog[$app])) return array();
    $renderer = $catalog[$app]['configRenderer'];
    return function_exists($renderer) ? $renderer($user, $home, $port, $password, $peerPort) : array();
}

/** Bootstrap runs as the account; the secured marker is the final write. */
function pmssToggleAppBootstrap(string $app, string $user, string $home, int $port, string $proxy, int $peerPort = 0): bool
{
    $catalog = pmssToggleAppCustomerCatalog();
    if (!isset($catalog[$app]) || preg_match('/^[a-z][a-z0-9]{0,7}$/D', $user) !== 1
        || $port < 2000 || $port > 38000 || ($home !== '/home/'.$user && getenv('PMSS_TEST_MODE') !== '1')) return false;
    $row = $catalog[$app];
    if ($row['extraPortServices'] !== array() && ($peerPort < 2000 || $peerPort > 38000 || $peerPort === $port)) return false;
    $login = $home.'/'.$row['loginFile'];
    $secured = $home.'/'.$row['securedMarker'];
    $proxyPath = $home.'/.lighttpd/custom.d/pmss-'.$app.'.conf';
    if (isset($row['dataDirectory']) && !is_dir($home.'/'.$row['dataDirectory'])
        && !@mkdir($home.'/'.$row['dataDirectory'], 0700, true)) return false;
    $saved = !is_link($login) && is_file($login) ? @file_get_contents($login) : false;
    $lines = is_string($saved) ? explode("\n", trim($saved)) : [];
    $password = count($lines) === 2 && $lines[0] === $user && preg_match('/^[a-f0-9]{48}$/D', $lines[1]) === 1 ? $lines[1] : '';
    $configs = pmssToggleAppConfigs($app, $user, $home, $port, $password, $peerPort);
    if (!isset($configs[$row['config']])) return false;
    $configValid = $password !== '' && (@fileperms($login) & 0777) === 0600;
    // qui keeps its single account in qui.db; without it the app would start with an empty user table.
    $quiDb = $home.'/'.dirname($row['config']).'/qui.db';
    if ($row['credentialMode'] === 'qui') $configValid = $configValid && is_file($quiDb) && !is_link($quiDb);
    foreach ($configs as $relative => $contents) {
        $path = $home.'/'.$relative;
        $configValid = $configValid && !is_link($path) && is_file($path)
            && (@fileperms($path) & 0777) === 0600 && @file_get_contents($path) === $contents;
    }
    if (!is_file($secured) || !$configValid) {
        // A previous marker must not authorize a start after a failed repair.
        if (is_file($secured) || is_link($secured)) @unlink($secured);
        $password = bin2hex(random_bytes(24));
        foreach (pmssToggleAppConfigs($app, $user, $home, $port, $password, $peerPort) as $relative => $contents) {
            if (!pmssToggleAppPrivateWrite($home.'/'.$relative, $contents)) return false;
        }
        if ($row['credentialMode'] === 'qui') {
            $binary = $row['exe'];
            if (getenv('PMSS_TEST_MODE') === '1' && getenv('PMSS_TEST_QUI_BINARY')) $binary = getenv('PMSS_TEST_QUI_BINARY');
            if (!pmssToggleAppQuiAccountSet($binary, dirname($quiDb), $user, $password)) return false;
            if (!is_file($quiDb) || is_link($quiDb)) return false;
        }
        if (!pmssToggleAppPrivateWrite($login, $user."\n".$password."\n")) return false;
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
    $catalog = pmssToggleAppCustomerCatalog();
    if (!isset($catalog[$app])
        || !in_array($state, ['running', 'stopped', 'not secured', 'gave up'], true)) return false;
    return pmssToggleAppPrivateWrite($home.'/'.$catalog[$app]['stateFile'], $state."\n");
}

/** Atomic replacement changes the marker inode without root reading a home file. */
function pmssToggleAppRestartSignal(string $app, string $home): bool
{
    $catalog = pmssToggleAppCustomerCatalog();
    if (!isset($catalog[$app])) return false;
    return pmssToggleAppPrivateWrite($home.'/'.$catalog[$app]['restartFile'], "restart\n");
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
