<?php
/**
 * PMSS: On-demand HTML5 shell console launcher (GH #326).
 *
 * Served under the per-user lighttpd, so it already runs as the customer UID.
 * Starts a ttyd instance bound to a private UNIX socket inside the customer's
 * own tree, then loads the terminal through the /user-<user>/console/
 * reverse-proxy path defined in template.lighttpd. ttyd runs as the customer
 * — the same privilege the customer already holds over SSH. NO root, NO
 * privilege escalation, NO persistent daemon.
 *
 * Security notes:
 *  - Reachable only behind the existing per-user htpasswd auth (/user-<user>/).
 *  - ttyd binds a loopback UNIX socket, never a TCP port; --check-origin is set.
 *  - No user-controlled input enters the spawned command (mode is a fixed enum).
 *  - Customer-tree separation (AGENTS.md): this file has ZERO require/include —
 *    it must never traverse /scripts/. Keep it self-contained.
 *
 * @license GPL-3.0-only
 * @author PMSS Team
 */

/**
 * Return true only when something accepts connections on the console socket.
 *
 * Neither the socket file nor the process list is a liveness signal: ttyd
 * --once exits without unlinking its socket, and a process-list search run
 * through exec() matches its own `sh -c` wrapper (GH #807).
 * A bare connect never reaches ttyd's WebSocket callbacks, so it does not use
 * up the single --once client.
 */
function pmssConsoleSocketLive($sock)
{
    if (!file_exists($sock)) {
        return false;
    }
    $conn = @stream_socket_client('unix://'.$sock, $errno, $errstr, 0.5);
    if ($conn === false) {
        return false;
    }
    fclose($conn);
    return true;
}

/** List account ttyd PIDs: true = idle console, false = busy, null = other ttyd. */
function pmssConsoleTtydProcesses($uid, $ttyd, $sock, $procRoot = '/proc', $pid = null)
{
    if (!is_int($uid) || $uid < 1) return null;
    $dirs = glob($procRoot.'/'.($pid === null ? '[0-9]*' : (int) $pid), GLOB_ONLYDIR);
    if ($dirs === false || !is_readable($procRoot)) return null;
    $processes = array();
    foreach ($dirs as $dir) {
        $status = @file_get_contents($dir.'/status');
        if (!is_string($status) || preg_match('/^Uid:\s+(\d+)\s+(\d+)/m', $status, $match) !== 1
            || (int) $match[1] !== $uid || (int) $match[2] !== $uid
            || trim((string) @file_get_contents($dir.'/comm')) !== 'ttyd') continue;
        $pid = (int) basename($dir);
        if ($pid < 1) continue;
        $processes[$pid] = null;
        $exe = @readlink($dir.'/exe');
        $cmdline = @file_get_contents($dir.'/cmdline');
        // An unreadable identity might be our console: block replacement safely.
        if (!is_string($exe) || !is_string($cmdline)) { $processes[$pid] = false; continue; }
        if (($exe !== $ttyd && $exe !== $ttyd.' (deleted)')
            || strpos($cmdline, "\0--once\0") === false
            || strpos($cmdline, "\0-i\0".$sock."\0") === false) continue;
        // ttyd starts its shell only after a WebSocket connects. Unknown children
        // or an existing shell must never be treated as an abandoned helper.
        $children = @file_get_contents($dir.'/task/'.$pid.'/children');
        $processes[$pid] = is_string($children) && trim($children) === '';
    }
    return $processes;
}

/** Reap abandoned console helpers and allow a spawn only after they have exited. */
function pmssConsoleSpawnAllowed($uid, $ttyd, $sock, $ops = array())
{
    $read = $ops['processes'] ?? 'pmssConsoleTtydProcesses';
    $signal = $ops['signal'] ?? static function ($pid) {
        return function_exists('posix_kill') && @posix_kill($pid, 15);
    };
    $pause = $ops['pause'] ?? static function () { usleep(25000); };
    $processes = $read($uid, $ttyd, $sock);
    if ($processes === null) return false;
    foreach ($processes as $pid => $idle) {
        if ($idle !== true) continue;
        // Recheck identity and children immediately before signalling.
        $current = $read($uid, $ttyd, $sock, '/proc', $pid);
        if ($current === null) return false;
        if (($current[$pid] ?? false) === true && !$signal($pid)) return false;
    }
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $processes = $read($uid, $ttyd, $sock);
        if ($processes === null) return false;
        // Never unlink beneath a remaining console, even if its probe timed out.
        if (count($processes) < 3 && array_filter($processes, 'is_bool') === array()) return true;
        $pause();
    }
    return false;
}

// Resolve the running customer from the process identity — never from request data.
$uid  = function_exists('posix_getuid') ? posix_getuid() : null;
$pw   = ($uid !== null && function_exists('posix_getpwuid')) ? posix_getpwuid($uid) : false;
$home = ($pw && !empty($pw['dir'])) ? $pw['dir'] : (getenv('HOME') ?: '');
$name = ($pw && !empty($pw['name'])) ? $pw['name'] : ($home !== '' ? basename($home) : '');

if ($home === '' || $name === '' || !is_dir($home)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Console unavailable: could not resolve account.';
    return;
}

$ttyd = '/usr/bin/ttyd';
$sock = $home.'/.lighttpd/console.sock';

// ttyd not yet deployed by update.php — fail soft with a friendly message, not a 500 loop.
if (!is_file($ttyd) || !is_executable($ttyd)) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Console</title>'
        .'<body style="font-family:sans-serif;padding:2em;max-width:40em">'
        .'<h2>Console not available yet</h2>'
        .'<p>The browser shell console is still being provisioned on this server. '
        .'Please try again shortly.</p></body>';
    return;
}

// Mode is a FIXED enum — request text never reaches the spawned command.
$persist  = (isset($_GET['mode']) && $_GET['mode'] === 'persist');
$shellCmd = $persist ? 'tmux new -A -s console' : 'bash';

// ttyd receives the full external path (mod_proxy does not strip), so the
// base-path must match the reverse-proxy prefix for asset/WebSocket resolution.
$basePath = '/user-'.$name.'/console/';

// Reuse a live console only if its socket accepts a connection; otherwise the
// spawn block clears the stale socket, respawns ttyd and refreshes the
// console-error.log diagnostic (GH #789, #807; ADR-0031).
// Serialize probe/unlink/spawn. Close-on-exec keeps detached ttyd from holding
// this request's lock forever; failed locks must never reach the spawn path.
$consoleLock = @fopen($home.'/.lighttpd/console.lock', 'ce');
if ($consoleLock === false || !@flock($consoleLock, LOCK_EX | LOCK_NB)) {
    http_response_code(503);
    header('Retry-After: 2');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Console is starting. Please try again.';
    return;
}
$running = pmssConsoleSocketLive($sock);

if (!$running) {
    if (!pmssConsoleSpawnAllowed($uid, $ttyd, $sock)) {
        error_log('[console] ttyd cleanup or process limit prevented a new console.');
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Console temporarily unavailable. Please try again.';
        return;
    }
    @unlink($sock); // clear a stale socket left by a previously exited ttyd

    // -W writable, --once exit-on-disconnect (no lingering daemon),
    // --check-origin reject cross-origin WS, -i UNIX socket, -b reverse-proxy base.
    $cmd = escapeshellarg($ttyd)
        .' -W --once --check-origin'
        .' -i '.escapeshellarg($sock)
        .' -b '.escapeshellarg($basePath)
        .' -t fontSize=15 -t disableReconnect=true'
        .' '.$shellCmd;

    // Start the shell in the customer's HOME. The per-user lighttpd (and its
    // php-cgi children) inherit cwd=/root from the root cron that starts them,
    // so without this cd the console opens in an inaccessible /root. Keep
    // ttyd diagnostics in the private lighttpd tree; they are surfaced only
    // when the socket never appears, not as customer-session output.
    // lighttpd bin-copy-environment gives php-cgi only PATH/SHELL/USER, so set HOME to match the cd (GH #968).
    $spawnLog = $home.'/.lighttpd/console-error.log';
    $spawn = 'cd '.escapeshellarg($home).' 2>/dev/null || exit 1; '
        .'export HOME='.escapeshellarg($home).'; '
        .': > '.escapeshellarg($spawnLog).' 2>/dev/null || exit 1; '
        .'exec '.$cmd.' </dev/null >/dev/null 2>>'.escapeshellarg($spawnLog);
    // Fully detach so ttyd outlives this php-cgi request. Capture outer-shell
    // failures too: bare setsid does not propagate the child's exit status.
    @exec('setsid sh -c '.escapeshellarg($spawn)
        .' >/dev/null 2>>'.escapeshellarg($spawnLog).' &');

    // Give ttyd a moment to create the socket before the iframe hits the proxy.
    for ($i = 0; $i < 100 && !file_exists($sock); $i++) {
        usleep(20000); // up to ~2s
    }

    if (!file_exists($sock)) {
        $spawnError = @file_get_contents($spawnLog);
        $spawnError = is_string($spawnError) ? trim($spawnError) : '';
        $spawnError = preg_replace('/[\r\n]+/', ' | ', $spawnError);
        $spawnError = is_string($spawnError) ? substr($spawnError, -4096) : '';
        error_log('[console] ttyd did not create console.sock.'
            .($spawnError !== '' ? ' stderr: '.$spawnError : ''));

        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><head><script>var u = new URL(location.href); if (u.username || u.password) { u.username = \'\'; u.password = \'\'; location.replace(u.href); }</script><meta charset="utf-8"><title>Console unavailable</title></head>'
            .'<body style="font-family:sans-serif;padding:2em;max-width:40em">'
            .'<h2>Console temporarily unavailable</h2>'
            .'<p>The browser shell could not be started. Please try again.</p>'
            .'<p><a href="console.php">Try again</a></p></body>';
        return;
    }
}
fclose($consoleLock);

// Load the terminal via the reverse-proxy path (relative -> /user-<user>/console/).
$label = $persist ? 'persistent tmux session (reattaches on reconnect)'
                   : 'ephemeral session (ends when you close this tab)';
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html lang="en"><head><script>var u = new URL(location.href); if (u.username || u.password) { u.username = \'\'; u.password = \'\'; location.replace(u.href); }</script><meta charset="utf-8">'
    .'<meta name="viewport" content="width=device-width, initial-scale=1">'
    .'<title>Shell console</title>'
    .'<style>html,body{margin:0;height:100%;background:#101216}'
    .'iframe{border:0;width:100%;height:100%;display:block}</style></head>'
    .'<body><iframe id="terminal" src="console/" title="'.htmlspecialchars($label, ENT_QUOTES).'">'
    .'</iframe><script>'
    .'/* ttyd --once exits on disconnect; its in-frame retry cannot restart it. */'
    .'setInterval(function () {'
    .'var frame = document.getElementById("terminal");'
    .'var page = frame.contentDocument;'
    .'if (!page) return;'
    .'var overlays = page.querySelectorAll(".xterm > div");'
    .'for (var i = 0; i < overlays.length; i++) {'
    .'if (overlays[i].textContent === "Press ⏎ to Reconnect") {'
    .'window.location.reload(); return;'
    .'}'
    .'}'
    .'}, 500);'
    .'</script></body></html>';
