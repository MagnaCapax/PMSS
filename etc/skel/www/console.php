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

// Reuse a live console only if the ttyd process AND its socket are both present.
// A bare pgrep match is not a liveness signal: a matched-but-dead ttyd whose
// socket has vanished would be trusted, the spawn block skipped, and the
// customer served a bare lighttpd mod_proxy 503 with no console-error.log
// diagnostic (GH #789). Requiring the socket file falls through to the spawn
// block, which clears the stale socket, respawns ttyd, and restores the
// diagnostic. Extends ADR-0031 (only spawn failures reach the user's log).
$running = false;
$out = [];
@exec('pgrep -u '.escapeshellarg($name).' -f '.escapeshellarg('ttyd .*'.$sock).' 2>/dev/null', $out, $rc);
if ($rc === 0 && !empty($out) && file_exists($sock)) {
    $running = true;
}

if (!$running) {
    @unlink($sock); // clear a stale socket left by a previously exited ttyd

    // -W writable, --once exit-on-disconnect (no lingering daemon),
    // --check-origin reject cross-origin WS, -i UNIX socket, -b reverse-proxy base.
    $cmd = escapeshellarg($ttyd)
        .' -W --once --check-origin'
        .' -i '.escapeshellarg($sock)
        .' -b '.escapeshellarg($basePath)
        .' -t fontSize=15'
        .' '.$shellCmd;

    // Start the shell in the customer's HOME. The per-user lighttpd (and its
    // php-cgi children) inherit cwd=/root from the root cron that starts them,
    // so without this cd the console opens in an inaccessible /root. Keep
    // ttyd diagnostics in the private lighttpd tree; they are surfaced only
    // when the socket never appears, not as customer-session output.
    $spawnLog = $home.'/.lighttpd/console-error.log';
    $spawn = 'cd '.escapeshellarg($home).' 2>/dev/null || exit 1; '
        .': > '.escapeshellarg($spawnLog).' 2>/dev/null || exit 1; '
        .$cmd.' </dev/null >/dev/null 2>>'.escapeshellarg($spawnLog);
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
        echo '<!doctype html><meta charset="utf-8"><title>Console unavailable</title>'
            .'<body style="font-family:sans-serif;padding:2em;max-width:40em">'
            .'<h2>Console temporarily unavailable</h2>'
            .'<p>The browser shell could not be started. Please try again.</p>'
            .'<p><a href="console.php">Try again</a></p></body>';
        return;
    }
}

// Load the terminal via the reverse-proxy path (relative -> /user-<user>/console/).
$label = $persist ? 'persistent tmux session (reattaches on reconnect)'
                   : 'ephemeral session (ends when you close this tab)';
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
    .'<meta name="viewport" content="width=device-width, initial-scale=1">'
    .'<title>Shell console</title>'
    .'<style>html,body{margin:0;height:100%;background:#101216}'
    .'iframe{border:0;width:100%;height:100%;display:block}</style></head>'
    .'<body><iframe src="console/" title="'.htmlspecialchars($label, ENT_QUOTES).'"></iframe></body></html>';
