<?php
/** Account-side UI opener. This file is self-contained for the customer PHP tree. */
const PMSS_PIKKUMIES_UI_TOKEN_DIR = '/.config/pmss/ui/tokens/'; // Reserved for port C.

/** Render one fixed launcher state without exposing a token or account path. */
function pmssPikkumiesOpenPage($state, $started)
{
    $pages = [
        'ready' => ['Getting Pikkumies ready.', 'The first time can take several minutes. This page updates by itself.'],
        'setup' => ['Pikkumies needs a one-time setup.', 'It takes a few minutes and happens once.'],
        'moving' => ['Your account is being moved to a new server.', 'Pikkumies returns when the move finishes.'],
        'error' => ['Pikkumies cannot open for this account yet.', 'Please contact support.'],
        'cross-site' => ['Open Pikkumies from your control panel.', ''],
    ];
    $copy = $pages[$state] ?? $pages['error'];
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    if ($state === 'ready') header('Refresh: 5');
    if ($state === 'cross-site') http_response_code(403);
    $slow = $started > 0 && time() - $started > 180 && $state === 'ready'
        ? ' Taking longer than usual. Contact support.' : '';
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Pikkumies</title><style>body{background:#111827;color:#f9fafb;font:14px system-ui;margin:0;padding:16px}'
        .'main{max-width:480px;margin:10vh auto;padding:24px;border:1px solid #374151;border-radius:12px}'
        .'h1{font-size:18px}a{color:#93c5fd}</style><main><h1>'.htmlspecialchars($copy[0], ENT_QUOTES, 'UTF-8').'</h1>';
    if ($copy[1] !== '' || $slow !== '') echo '<p>'.htmlspecialchars($copy[1].$slow, ENT_QUOTES, 'UTF-8').'</p>';
    if ($state === 'cross-site') echo '<p><a href="index.php">Open your control panel</a></p>';
    if ($state === 'error') echo '<p><a href="mailto:support@pulsedmedia.com">Contact support</a></p>';
    echo '</main></html>';
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
function pmssPikkumiesOpenTlsValid($name, $port = 844)
{
    if (!in_array($port, [443, 844], true)) return false;
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
function pmssPikkumiesOpenCertificateState($home, $local, $name, $host, $permalink)
{
    $flag = $home.'/.request-web-certs';
    if (is_file($flag.'.failed')) return 'error';
    if (file_exists($flag) || is_link($flag)) return 'pending';
    $serviceAddresses = @gethostbynamel($permalink);
    $hostAddresses = @gethostbynamel($host);
    if (!is_array($serviceAddresses) || !is_array($hostAddresses)
        || array_intersect($serviceAddresses, $hostAddresses) === []) return 'pending';
    $cache = $local.'/cert-probe';
    if (is_file($cache) && !is_link($cache) && time() - (int) @filemtime($cache) < 120) {
        $valid = trim((string) @file_get_contents($cache)) === 'valid';
    } else {
        $valid = pmssPikkumiesOpenTlsValid($permalink);
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

// Derive identity from the PHP process, never from a request parameter.
$uid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
$account = $uid >= 0 && function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;
$home = is_array($account) ? (string) ($account['dir'] ?? '') : '';
$name = is_array($account) ? (string) ($account['name'] ?? '') : '';
$sameOrigin = ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'same-origin'
    || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'none';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || !$sameOrigin) {
    pmssPikkumiesOpenPage('cross-site', 0);
    return;
}

$ui = $_GET['ui'] ?? 'simplified';
if (!is_string($ui) || !in_array($ui, ['simplified', 'hermes', 'vanilla-hermes-web', 'vanilla-agentboard'], true)) {
    pmssPikkumiesOpenPage('error', 0);
    return;
}
// B/C remain closed until their separate authenticated backends are provisioned.
if ($ui !== 'simplified' || $home === '' || $name === ''
    || !is_file('/etc/seedbox/config/pikkumiesEternals.enabled')) {
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
    pmssPikkumiesOpenPage('error', 0);
    return;
}
$host = strtolower(trim((string) @shell_exec('/bin/hostname -f')));
if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/D', $host) !== 1) {
    pmssPikkumiesOpenPage('error', 0);
    return;
}
$permalink = substr(hash('sha256', 'mcx.fi:service:'.ltrim($billingId, '0')), 0, 16).'.mcx.fi';
$origin = 'https://'.$permalink.':844';
$panelOrigin = 'https://'.$host;
$launcherUrl = $panelOrigin.'/user-'.$name.'/pikkumiesOpen.php';
$socket = $local.'/ui/ui.sock';
$started = isset($_GET['started']) && ctype_digit((string) $_GET['started']) ? (int) $_GET['started'] : time();
$certificateState = pmssPikkumiesOpenCertificateState($home, $local, $name, $host, $permalink);
if ($certificateState === 'error') {
    pmssPikkumiesOpenPage('error', $started);
    return;
}
if (($_GET['view'] ?? '') === 'status') {
    pmssPikkumiesOpenPage('ready', $started);
    return;
}
if ($certificateState !== 'valid') {
    pmssPikkumiesOpenPage('ready', $started);
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
