<?php
/** Account-side UI opener. This file is self-contained for the customer PHP tree. */
const PMSS_PIKKUMIES_UI_TOKEN_DIR = '/.config/pmss/ui/tokens/'; // Reserved for port C.

/** Render one fixed launcher state without exposing a token or account path. */
function pmssPikkumiesOpenPage($state, $started)
{
    $pages = [
        'ready' => ['Getting Pikkumies ready.', 'The first time can take several minutes. This page updates by itself.'],
        'setup' => ['Pikkumies needs a one-time setup.', 'It takes a few minutes and happens once.'],
        'setting-up' => ['Setting up Pikkumies.', 'This happens once. You can leave this page.'],
        'memory' => ['Not enough memory for Pikkumies.', 'Your account has less memory than the about 1 GB it needs.'],
        'moving' => ['Your account is being moved to a new server.', 'Pikkumies returns when the move finishes.'],
        'error' => ['Pikkumies cannot open for this account yet.', 'Please contact support.'],
        'cross-site' => ['Open Pikkumies from your control panel.', ''],
        'old-browser' => ['Your browser is too old for Pikkumies. Please update it.', ''],
        'not-running' => ['Pikkumies is not running right now.', 'Open it again from your control panel.'],
    ];
    $copy = $pages[$state] ?? $pages['error'];
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $ui = ($_GET['ui'] ?? '') === 'hermes' ? 'hermes' : 'simplified';
    $retry = 'pikkumiesOpen.php?ui='.$ui.'&started='.(int) $started;
    if ($state === 'ready' || $state === 'setting-up') header('Refresh: 5; url='.$retry);
    if ($state === 'cross-site' || $state === 'old-browser') http_response_code(403);
    $slow = $started > 0 && time() - $started > ($state === 'ready' ? 900 : 180)
        ? ' Taking longer than usual. Contact support.' : '';
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Pikkumies</title><style>html{color-scheme:dark}body{background:#0f1720;color:#f9fafb;font:14px system-ui;margin:0;padding:16px}'
        .'main{max-width:480px;margin:10vh auto;padding:24px;border:1px solid #374151;border-radius:12px}'
        .'h1{font-size:18px}a{color:#93c5fd}</style><main><h1>'.htmlspecialchars($copy[0], ENT_QUOTES, 'UTF-8').'</h1>';
    if ($copy[1] !== '' || $slow !== '') echo '<p>'.htmlspecialchars($copy[1].$slow, ENT_QUOTES, 'UTF-8').'</p>';
    if ($state === 'cross-site') echo '<p><a href="index.php">Open your control panel</a></p>';
    if ($state === 'setup') echo '<p><a href="pikkumiesOpen.php?ui='.$ui.'&amp;view=setup">Set up now</a></p>';
    if ($state === 'memory') echo '<p><a href="mailto:support@pulsedmedia.com">Contact support</a></p>';
    if ($state === 'not-running') echo '<p><button onclick="location.href=\'pikkumiesOpen.php?session=pm1\'">Reconnect</button></p>';
    if ($state === 'error') echo '<p><a href="mailto:support@pulsedmedia.com">Contact support</a></p>';
    echo '</main></html>';
}

/** Read the production v1 memory ceiling, including parent slice limits. */
function pmssPikkumiesOpenMemoryLow($cgroupFile = '/proc/self/cgroup', $root = '/sys/fs/cgroup/memory')
{
    $rows = @file($cgroupFile, FILE_IGNORE_NEW_LINES);
    if (!is_array($rows)) return false;
    foreach ($rows as $row) {
        $parts = explode(':', $row, 3);
        if (count($parts) !== 3 || !in_array('memory', explode(',', $parts[1]), true)) continue;
        $relative = '/'.ltrim($parts[2], '/');
        do {
            foreach (['memory.limit_in_bytes', 'memory.soft_limit_in_bytes'] as $file) {
                $path = rtrim($root, '/').$relative.'/'.$file;
                $raw = @file_get_contents($path, false, null, 0, 32);
                if (is_string($raw) && preg_match('/^[1-9][0-9]*\s*$/D', $raw)
                    && (int) $raw < 1000 * 1048576) return true;
            }
            if ($relative === '/') break;
            $relative = dirname($relative);
        } while ($relative !== '.');
    }
    return false;
}

/** Status never starts the UI or mints a bearer token. */
function pmssPikkumiesOpenStatusEmit($visible, $ready, $origin = '', $setup = false)
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(array('visible' => (bool) $visible, 'ready' => (bool) $ready,
        'setup' => (bool) $setup,
        'origin' => $visible ? $origin : ''));
}

/** An account-local socket is live only when it accepts a connection. */
function pmssPikkumiesOpenSocketLive($socket)
{
    if (!is_file($socket) && !file_exists($socket)) return false;
    $stream = @stream_socket_client('unix://'.$socket, $errno, $error, 0.3);
    if (!is_resource($stream)) return false;
    fclose($stream);
    return true;
}

/** A live account transfer hold keeps the launcher out of the copied state. */
function pmssPikkumiesOpenMoving($local)
{
    $path = $local.'/eternals.hold';
    if (!is_file($path) || is_link($path)) return false;
    $data = json_decode((string) @file_get_contents($path, false, null, 0, 64), true);
    return is_array($data) && isset($data['until']) && is_int($data['until'])
        && $data['until'] > time();
}

/** Delete expired launch tokens, then publish at most one new 0600 token. */
function pmssPikkumiesOpenTokenMint($directory)
{
    if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700, true))) return '';
    @chmod($directory, 0700);
    $live = [];
    foreach (glob($directory.'/*') ?: [] as $path) {
        if (preg_match('/^[0-9a-f]{64}$/D', basename($path)) !== 1 || is_link($path)) continue;
        if (!is_file($path) || time() - (int) @filemtime($path) >= 60) {
            @unlink($path);
            continue;
        }
        $live[] = $path;
    }
    if (count($live) >= 8) return '';
    $token = bin2hex(random_bytes(32));
    $path = $directory.'/'.$token;
    $file = @fopen($path, 'x');
    if (!is_resource($file)) return '';
    $ok = @chmod($path, 0600) && @fwrite($file, $token."\n") === 65 && @fflush($file);
    fclose($file);
    if (!$ok) { @unlink($path); return ''; }
    return $token;
}

/** Verify the published name against this host's TLS listener. */
/** Run one account Pikkumies verb detached, once at a time (flock on $lock), appending its output to $log. */
function pmssPikkumiesOpenBackground($binary, $verb, $lock, $log)
{
    if (!is_dir(dirname($lock))) @mkdir(dirname($lock), 0700, true);
    // setsid: a process left in php-cgi's group dies when php-cgi recycles (live QA 2026-10-21).
    exec('umask 077 && /usr/bin/setsid flock -n -o '.escapeshellarg($lock).' nohup '.escapeshellarg($binary).' '.$verb
        .' </dev/null >>'.escapeshellarg($log).' 2>&1 &');
}

function pmssPikkumiesOpenTlsValid($name, $port = 844)
{
    if (!in_array($port, [443, 844, 845, 846], true)) return false;
    $context = stream_context_create(['ssl' => [
        'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $name,
        'SNI_enabled' => true, 'SNI_server_name' => $name,
    ]]);
    $stream = @stream_socket_client('ssl://127.0.0.1:'.$port, $errno, $error, 2,
        STREAM_CLIENT_CONNECT, $context);
    if (!is_resource($stream)) return false;
    fclose($stream);
    return true;
}

/** Request one username-free lineage only after DNS points at this host. */
function pmssPikkumiesOpenCertificateState($home, $local, $name, $host, $permalink, $port = 844)
{
    $flag = $home.'/.request-web-certs';
    if (is_file($flag.'.failed')) return 'error';
    if (file_exists($flag) || is_link($flag)) return 'pending';
    $serviceAddresses = @gethostbynamel($permalink);
    $hostAddresses = @gethostbynamel($host);
    if (!is_array($serviceAddresses) || !is_array($hostAddresses)
        || array_intersect($serviceAddresses, $hostAddresses) === []) return 'pending';
    $cache = $local.'/cert-probe-'.$port;
    if (is_file($cache) && !is_link($cache) && time() - (int) @filemtime($cache) < 120) {
        $valid = trim((string) @file_get_contents($cache)) === 'valid';
    } else {
        $valid = pmssPikkumiesOpenTlsValid($permalink, $port);
        if (is_dir(dirname($cache)) && !is_link($cache)) {
            @file_put_contents($cache, $valid ? 'valid' : 'pending');
            @chmod($cache, 0600);
        }
    }
    if ($valid) return 'valid';
    $mode = pmssPikkumiesOpenTlsValid($name.'.'.$host, 443) ? 'username' : 'default';
    $file = @fopen($flag, 'x');
    if (!is_resource($file)) return 'pending';
    $ok = @chmod($flag, 0644) && @fwrite($file, $mode."\n") === strlen($mode) + 1;
    fclose($file);
    if (!$ok) { @unlink($flag); return 'error'; }
    return 'pending';
}

/** Check and spawn inside an account-local flock whose fd closes before the child. */
function pmssPikkumiesOpenStart($binary, $local, $socket, $origin, $panelOrigin, $launcherUrl)
{
    $ui = $local.'/ui';
    if (is_link($ui) || (!is_dir($ui) && !@mkdir($ui, 0700, true))) return false;
    @chmod($ui, 0700);
    $lock = $ui.'/launch.lock';
    $log = $ui.'/launcher.log';
    $probe = '$s=@stream_socket_client("unix://".$argv[1],$e,$m,0.2); if (!is_resource($s)) exit(1); fclose($s);';
    $script = 'if ! /usr/bin/php -r '.escapeshellarg($probe).' -- '.escapeshellarg($socket)
        .' >/dev/null 2>&1; then rm -f -- '.escapeshellarg($socket).'; '
        .'AGENTBOARD_LAUNCHER_URL='.escapeshellarg($launcherUrl).' '
        .'AGENTBOARD_ALLOWED_ORIGIN='.escapeshellarg($origin).' '
        .'AGENTBOARD_FRAME_ANCESTORS='.escapeshellarg($panelOrigin).' '
        .escapeshellarg($binary).' ui start >>'.escapeshellarg($log).' 2>&1; fi';
    $command = 'flock -x -w 5 -o '.escapeshellarg($lock).' sh -c '.escapeshellarg($script);
    exec($command, $output, $rc);
    if ($rc !== 0) return false;
    for ($i = 0; $i < 30; $i++) {
        if (pmssPikkumiesOpenSocketLive($socket)) return true;
        usleep(100000);
    }
    return false;
}

/** Account-owned standard Agentboard has its own socket, tokens, and data. */
function pmssHermesAgentboardGateProbe($socket)
{
    $stream = @stream_socket_client('unix://'.$socket, $errno, $error, 1);
    if (!is_resource($stream)) return false;
    stream_set_timeout($stream, 1);
    fwrite($stream, "GET /api/sessions HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
    $status = fgets($stream, 256);
    fclose($stream);
    return is_string($status) && preg_match('/^HTTP\/1\.[01] 401(?: |$)/', trim($status)) === 1;
}

/** Resolve one published version for both executable and frontend paths. */
function pmssHermesAgentboardVersionPaths($link)
{
    $binary = realpath($link);
    if (!is_string($binary)) return false;
    $staticDir = dirname($binary).'/dist/client';
    if (!is_file($binary) || !is_executable($binary) || !is_file($staticDir.'/index.html')) return false;
    return [$binary, $staticDir];
}

function pmssHermesAgentboardStart($home, $origin, $panelOrigin, $launcherUrl)
{
    $paths = pmssHermesAgentboardVersionPaths('/opt/pikkumies-agentboard/agentboard');
    if ($paths === false) return false;
    [$binary, $staticDir] = $paths;
    $base = $home.'/.config/pmss/ui';
    $socket = $base.'/agentboard.sock';
    foreach ([$base, $base.'/tokens', $base.'/data', $base.'/tmp', $base.'/tmux'] as $dir) {
        if (is_link($dir) || (!is_dir($dir) && !@mkdir($dir, 0700, true))) return false;
        @chmod($dir, 0700);
    }
    if (!is_link($socket) && @fileowner($socket) === posix_geteuid()
        && pmssPikkumiesOpenSocketLive($socket)) {
        if (pmssHermesAgentboardGateProbe($socket)) return true;
        pmssHermesAgentboardStop($home);
        return false;
    }
    @unlink($socket);
    $env = 'HOME='.escapeshellarg($home).' TMPDIR='.escapeshellarg($base.'/tmp')
        .' TMUX_TMPDIR='.escapeshellarg($base.'/tmux')
        .' AGENTBOARD_MODE=standard AGENTBOARD_SOCKET='.escapeshellarg($socket)
        .' AGENTBOARD_TOKEN_DIR='.escapeshellarg($base.'/tokens')
        .' AGENTBOARD_DATA_DIR='.escapeshellarg($base.'/data')
        .' AGENTBOARD_STATIC_DIR='.escapeshellarg($staticDir)
        .' AGENTBOARD_ALLOWED_ORIGIN='.escapeshellarg($origin)
        .' AGENTBOARD_FRAME_ANCESTORS='.escapeshellarg($panelOrigin)
        .' AGENTBOARD_LAUNCHER_URL='.escapeshellarg($launcherUrl);
    $command = $env.' /usr/bin/setsid nohup '.escapeshellarg($binary).' </dev/null >>'.escapeshellarg($base.'/agentboard.log').' 2>&1 & echo $!';
    $rows = []; exec($command, $rows, $rc);
    $pid = trim((string) ($rows[0] ?? ''));
    if ($rc !== 0 || !preg_match('/^[1-9][0-9]*$/D', $pid)) return false;
    @file_put_contents($base.'/agentboard.pid', $pid."\n");
    for ($i = 0; $i < 30; ++$i) {
        if (!is_link($socket) && @fileowner($socket) === posix_geteuid()
            && pmssHermesAgentboardGateProbe($socket)) return true;
        usleep(100000);
    }
    pmssHermesAgentboardStop($home);
    return false;
}

/** Never signal a PID unless it is the account's pinned Agentboard process. */
function pmssHermesAgentboardStop($home)
{
    $pidFile = $home.'/.config/pmss/ui/agentboard.pid';
    clearstatcache(true, $pidFile);
    $pid = is_file($pidFile) && !is_link($pidFile) ? trim((string) @file_get_contents($pidFile)) : '';
    if (!preg_match('/^[1-9][0-9]*$/D', $pid)) return;
    $proc = '/proc/'.$pid;
    $cmd = @file_get_contents($proc.'/cmdline');
    $arguments = is_string($cmd) ? explode("\0", $cmd) : [];
    foreach ($arguments as $argument) {
        if (@fileowner($proc) === posix_geteuid()
            && ($argument === '/opt/pikkumies-agentboard/agentboard'
                || preg_match('~\A/opt/pikkumies-agentboard/[A-Za-z0-9._-]+/agentboard\z~D', $argument) === 1)) {
            @posix_kill((int) $pid, defined('SIGTERM') ? SIGTERM : 15);
            return;
        }
    }
}

/** Older browsers may omit Fetch Site; require the panel's exact Referer origin. */
function pmssPikkumiesOpenSameOriginReferer($referer, $scheme, $authority)
{
    if (!is_string($referer) || !is_string($authority) || $referer === '' || $authority === '') return false;
    $source = parse_url($referer);
    $panel = parse_url($scheme.'://'.$authority);
    return is_array($source) && is_array($panel) && !isset($source['user']) && !isset($source['pass'])
        && strtolower($source['scheme'] ?? '') === strtolower($panel['scheme'] ?? '')
        && strtolower($source['host'] ?? '') === strtolower($panel['host'] ?? '')
        && ($source['port'] ?? ($scheme === 'https' ? 443 : 80)) === ($panel['port'] ?? ($scheme === 'https' ? 443 : 80));
}

// Derive identity from the PHP process, never from a request parameter.
$uid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
$account = $uid >= 0 && function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;
$home = is_array($account) ? (string) ($account['dir'] ?? '') : '';
$name = is_array($account) ? (string) ($account['name'] ?? '') : '';
$panelScheme = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'http' ? 'http' : 'https';
$sameOrigin = ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'same-origin'
    || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'none'
    || (!isset($_SERVER['HTTP_SEC_FETCH_SITE']) && pmssPikkumiesOpenSameOriginReferer(
        $_SERVER['HTTP_REFERER'] ?? '', $panelScheme, $_SERVER['HTTP_HOST'] ?? ''));
$statusView = ($_GET['view'] ?? '') === 'status';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || !$sameOrigin) {
    pmssPikkumiesOpenPage(!isset($_SERVER['HTTP_SEC_FETCH_SITE'])
        && ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'iframe' ? 'old-browser' : 'cross-site', 0);
    return;
}
if (($_GET['view'] ?? '') === 'not-running') {
    pmssPikkumiesOpenPage('not-running', 0);
    return;
}

$ui = $_GET['ui'] ?? 'simplified';
if ($ui === 'agentboard-pikkumies') {
    if (!$statusView && $home !== '') {
        $installer = $home.'/bin/install-hermes';
        if (is_file($installer) && !is_link($installer) && is_executable($installer))
            exec(escapeshellarg($installer).' stop >/dev/null 2>&1');
    }
    $ui = 'simplified';
}
if (!is_string($ui) || !in_array($ui, ['simplified', 'hermes', 'vanilla-hermes-web', 'vanilla-agentboard'], true)) {
    pmssPikkumiesOpenPage('error', 0);
    return;
}
if ($ui === 'vanilla-hermes-web' || $ui === 'vanilla-agentboard') {
    $billing = '/var/lib/pmss/public/billing/'.$name;
    $stat = @lstat($billing);
    $billingId = is_array($stat) && ($stat['mode'] & 0170000) === 0100000
        && $stat['uid'] === 0 && $stat['nlink'] === 1 ? trim((string) @file_get_contents($billing)) : '';
    $install = $home.'/.hermes/pmss-install.status';
    $choice = $home.'/.config/pmss/ui/hermes-interface';
    $chosen = is_file($choice) && !is_link($choice) ? trim((string) @file_get_contents($choice)) : 'vanilla-hermes-web';
    if ($home === '' || $name === '' || !preg_match('/^[1-9][0-9]*$/D', $billingId)
        || is_link($install) || trim((string) @file_get_contents($install)) !== 'ready' || $chosen !== $ui) {
        if ($statusView) { pmssPikkumiesOpenStatusEmit(false, false); return; }
        pmssPikkumiesOpenPage('error', 0);
        return;
    }
    $host = strtolower(trim((string) @shell_exec('/bin/hostname -f')));
    if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/D', $host) !== 1) {
        pmssPikkumiesOpenPage('error', 0); return;
    }
    $permalink = substr(hash('sha256', 'mcx.fi:service:'.ltrim($billingId, '0')), 0, 16).'.mcx.fi';
    $origin = 'https://'.$permalink.':846';
    $panelOrigin = 'https://'.$host;
    $launcherUrl = $panelOrigin.'/user-'.$name.'/pikkumiesOpen.php?ui='.$ui;
    $started = isset($_GET['started']) && ctype_digit((string) $_GET['started']) ? (int) $_GET['started'] : time();
    $cert = pmssPikkumiesOpenCertificateState($home, $home.'/.config/pmss/ui', $name, $host, $permalink, 846);
    if ($statusView) { pmssPikkumiesOpenStatusEmit(true, $cert === 'valid', $origin); return; }
    if ($cert !== 'valid') { pmssPikkumiesOpenPage($cert === 'error' ? 'error' : 'ready', $started); return; }
    $installer = $home.'/bin/install-hermes';
    if (!is_file($installer) || is_link($installer) || !is_executable($installer)) {
        pmssPikkumiesOpenPage('error', $started); return;
    }
    exec(escapeshellarg($installer).' reconcile-key >/dev/null 2>&1', $keyLines, $keyRc);
    if ($keyRc !== 0) { pmssPikkumiesOpenPage('error', $started); return; }
    if ($ui === 'vanilla-hermes-web') {
        pmssHermesAgentboardStop($home);
        exec(escapeshellarg($installer).' start >/dev/null 2>&1', $lines, $rc);
        if ($rc !== 0) { pmssPikkumiesOpenPage('error', $started); return; }
        header('Cache-Control: no-store');
        header('Location: '.$origin.'/__pmss-select-web', true, 302);
        return;
    }
    exec(escapeshellarg($installer).' stop >/dev/null 2>&1');
    if (!pmssHermesAgentboardStart($home, $origin, $panelOrigin, $launcherUrl)) {
        pmssPikkumiesOpenPage('error', $started); return;
    }
    $token = pmssPikkumiesOpenTokenMint($home.PMSS_PIKKUMIES_UI_TOKEN_DIR);
    if ($token === '') { pmssPikkumiesOpenPage('error', $started); return; }
    header('Cache-Control: no-store');
    header('Location: '.$origin.'/__pmss-select-agentboard#t='.$token, true, 302);
    return;
}
if (($ui !== 'simplified' && $ui !== 'hermes') || $home === '' || $name === ''
    || !is_file('/etc/seedbox/config/pikkumiesEternals.enabled')) {
    if ($statusView) { pmssPikkumiesOpenStatusEmit(false, false); return; }
    pmssPikkumiesOpenPage('error', 0);
    return;
}
$binary = $home.'/.mcxPikkumies/pikkumies/bin/pikkumies';
$local = $home.'/.mcxPikkumies/pikkumies/local';
$billing = '/var/lib/pmss/public/billing/'.$name;
$stat = @lstat($billing);
$billingId = is_array($stat) && ($stat['mode'] & 0170000) === 0100000 && $stat['uid'] === 0 && $stat['nlink'] === 1
    ? trim((string) @file_get_contents($billing)) : '';
if (!is_file($binary) || !is_executable($binary) || !preg_match('/^[1-9][0-9]*$/D', $billingId)
    || is_file($local.'/eternals.off') || !is_dir($local)) {
    if ($statusView) { pmssPikkumiesOpenStatusEmit(false, false); return; }
    pmssPikkumiesOpenPage('error', 0);
    return;
}
$host = strtolower(trim((string) @shell_exec('/bin/hostname -f')));
if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/D', $host) !== 1) {
    if ($statusView) { pmssPikkumiesOpenStatusEmit(false, false); return; }
    pmssPikkumiesOpenPage('error', 0);
    return;
}
$permalink = substr(hash('sha256', 'mcx.fi:service:'.ltrim($billingId, '0')), 0, 16).'.mcx.fi';
$port = $ui === 'hermes' ? 845 : 844;
$origin = 'https://'.$permalink.':'.$port;
$panelOrigin = 'https://'.$host;
$launcherUrl = $panelOrigin.'/user-'.$name.'/pikkumiesOpen.php'.($ui === 'hermes' ? '?ui=hermes' : '');
$socket = $local.'/ui/ui.sock';
$started = isset($_GET['started']) && ctype_digit((string) $_GET['started']) ? (int) $_GET['started'] : time();
if (pmssPikkumiesOpenMoving($local)) {
    if ($statusView) { pmssPikkumiesOpenStatusEmit(true, false, $origin); return; }
    pmssPikkumiesOpenPage('moving', $started);
    return;
}
if (pmssPikkumiesOpenMemoryLow()) {
    if ($statusView) { pmssPikkumiesOpenStatusEmit(true, false, $origin, true); return; }
    pmssPikkumiesOpenPage('memory', $started);
    return;
}
$runtimeReady = is_file($home.'/.mcxPikkumies/pikkumies/include/.hermes-ready');
if (($_GET['view'] ?? '') === 'setup' && !$runtimeReady) {
    pmssPikkumiesOpenBackground($binary, 'post-update', $local.'/ui/setup.lock', $local.'/install.log');
    pmssPikkumiesOpenPage('setting-up', $started);
    return;
}
if (!$runtimeReady) {
    if ($statusView) { pmssPikkumiesOpenStatusEmit(true, false, $origin, true); return; }
    pmssPikkumiesOpenPage('setup', $started);
    return;
}
$certificateState = pmssPikkumiesOpenCertificateState($home, $local, $name, $host, $permalink, $port);
if ($certificateState === 'error') {
    if ($statusView) { pmssPikkumiesOpenStatusEmit(true, false, $origin); return; }
    pmssPikkumiesOpenPage('error', $started);
    return;
}
// Power user: Hermes' dashboard page is built once, in the background, before its first start. The build takes
// minutes and the dashboard start below waits seconds (Pikkumies ADR-0033 addendum 2026-10-21, `ui web-build`).
if ($ui === 'hermes' && !is_file($home.'/.mcxPikkumies/pikkumies/hermes/hermes_cli/web_dist/index.html')) {
    if ($statusView) { pmssPikkumiesOpenStatusEmit(true, false, $origin, true); return; }
    pmssPikkumiesOpenBackground($binary, 'ui web-build', $local.'/ui/web-build.lock', $local.'/ui/web-build.log');
    pmssPikkumiesOpenPage('setting-up', $started);
    return;
}
if ($statusView) {
    if ($ui === 'hermes') {
        $runner = $home.'/bin/pikkumiesHermesUi.php';
        $ready = false;
        if ($certificateState === 'valid' && is_file($runner) && !is_link($runner)) {
            exec('HOME='.escapeshellarg($home).' /usr/bin/php '.escapeshellarg($runner).' ready >/dev/null 2>&1', $unused, $readyRc);
            $ready = $readyRc === 0;
        }
        pmssPikkumiesOpenStatusEmit(true, $ready, $origin);
        return;
    }
    $rows = [];
    exec('/usr/bin/timeout -k 1s 2s '.escapeshellarg($binary).' eternal list --json 2>/dev/null', $rows, $rowsRc);
    $eternals = $rowsRc === 0 ? json_decode(implode("\n", $rows), true) : null;
    $setup = false;
    if (is_array($eternals)) foreach ($eternals as $eternal) {
        if (is_array($eternal) && ($eternal['id'] ?? '') === 'pm1'
            && ($eternal['state'] ?? '') === 'waiting-for-setup') $setup = true;
    }
    pmssPikkumiesOpenStatusEmit(true, $certificateState === 'valid' && pmssPikkumiesOpenSocketLive($socket), $origin, $setup);
    return;
}
if ($certificateState !== 'valid') {
    pmssPikkumiesOpenPage('ready', $started);
    return;
}
if ($ui === 'hermes') {
    $runner = $home.'/bin/pikkumiesHermesUi.php';
    if (!is_file($runner) || is_link($runner)) { pmssPikkumiesOpenPage('error', $started); return; }
    exec('HOME='.escapeshellarg($home).' /usr/bin/php '.escapeshellarg($runner).' start '.escapeshellarg($origin).' >/dev/null 2>&1', $unused, $backendRc);
    if ($backendRc !== 0) { pmssPikkumiesOpenPage('error', $started); return; }
    header('Cache-Control: no-store');
    header('Location: '.$origin.'/', true, 302);
    return;
}
$list = [];
exec(escapeshellarg($binary).' eternal list --json 2>/dev/null', $list, $listRc);
if ($listRc !== 0 || !is_array(json_decode(implode("\n", $list), true))) {
    pmssPikkumiesOpenPage('error', $started);
    return;
}
if (!pmssPikkumiesOpenSocketLive($socket)
    && !pmssPikkumiesOpenStart($binary, $local, $socket, $origin, $panelOrigin, $launcherUrl)) {
    pmssPikkumiesOpenPage('ready', $started);
    return;
}
$token = pmssPikkumiesOpenTokenMint($local.'/ui/tokens');
if ($token === '') { pmssPikkumiesOpenPage('error', $started); return; }
header('Cache-Control: no-store');
header('Location: '.$origin.'/?session=pm1#t='.$token, true, 302);
