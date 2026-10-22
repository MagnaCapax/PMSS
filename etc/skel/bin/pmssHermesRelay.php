<?php
/** Account-owned Unix entrypoint for the two Hermes dashboard variants. */

function pmssHermesRelayListenerOwned(int $port, ?callable $owner = null): bool
{
    if ($owner !== null) return $owner($port) === true;
    $ss = pmssAgentCommandPath('ss');
    if ($ss === '') return false;
    $rows = array();
    exec(escapeshellarg($ss).' -H -ltnp '.escapeshellarg('sport = :'.$port).' 2>/dev/null', $rows, $rc);
    if ($rc !== 0) return false;
    foreach ($rows as $row) {
        if (!preg_match('/pid=([1-9][0-9]*),/', $row, $match)) continue;
        if (@fileowner('/proc/'.$match[1]) === posix_geteuid()) return true;
    }
    return false;
}

/** Verify the destination before accepting any bytes from the Unix peer. */
function pmssHermesRelayConnect(int $port, ?callable $owner = null)
{
    if (!pmssHermesRelayListenerOwned($port, $owner)) return false;
    $backend = @stream_socket_client('tcp://127.0.0.1:'.$port, $error, $message, 2);
    if ($backend === false) return false;
    if (!pmssHermesRelayListenerOwned($port, $owner)) { fclose($backend); return false; }
    return $backend;
}

/** The published socket must answer through the same login gate as TCP. */
function pmssHermesRelayReady(string $socket): bool
{
    $peer = @stream_socket_client('unix://'.$socket, $error, $message, 2);
    if ($peer === false) return false;
    stream_set_timeout($peer, 2);
    fwrite($peer, "GET /api/auth/me HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
    $status = fgets($peer);
    fclose($peer);
    return is_string($status) && preg_match('/^HTTP\/1\.[01] 401(?: |$)/', trim($status)) === 1;
}

/** Classify a connection's first bytes: false = forward them unchanged, null = need more, string = sign-in body. */
function pmssHermesRelayLoginRequest(string $buffer)
{
    $line = 'POST /__pmss-session HTTP/1.';
    if (strncmp($buffer, $line, min(strlen($buffer), strlen($line))) !== 0) return false;
    $end = strpos($buffer, "\r\n\r\n");
    if ($end === false) return strlen($buffer) > 8192 ? '' : null;
    $lines = explode("\r\n", substr($buffer, 0, $end));
    if (preg_match('/^POST \/__pmss-session HTTP\/1\.[01]$/D', (string) array_shift($lines)) !== 1) return '';
    $length = null;
    foreach ($lines as $header) {
        $parts = explode(':', $header, 2);
        $name = strtolower(trim($parts[0]));
        if ($name === 'transfer-encoding') return '';
        if ($name !== 'content-length') continue;
        if ($length !== null || preg_match('/^[0-9]{1,3}$/D', trim($parts[1] ?? '')) !== 1) return '';
        $length = (int) trim($parts[1]);
    }
    if ($length === null || $length > 256) return '';
    $body = (string) substr($buffer, $end + 4);
    if (strlen($body) < $length) return null;
    return strlen($body) === $length ? $body : '';
}

/** Spend one launcher token: a fresh, single-link 0600 file named by it in the account's private token directory. */
function pmssHermesRelayTokenClaim(string $directory, string $token): bool
{
    if (preg_match('/^[0-9a-f]{64}$/D', $token) !== 1) return false;
    clearstatcache();
    $uid = posix_geteuid();
    $dir = @lstat($directory);
    if (!is_array($dir) || ($dir['mode'] & 0170000) !== 0040000 || $dir['uid'] !== $uid || ($dir['mode'] & 0077) !== 0) return false;
    $path = $directory.'/'.$token;
    $file = @lstat($path);
    if (!is_array($file) || ($file['mode'] & 0170000) !== 0100000 || $file['uid'] !== $uid || $file['nlink'] !== 1
        || ($file['mode'] & 0077) !== 0 || $file['size'] !== 65) return false;
    $age = time() - $file['mtime'];
    if ($age < 0 || $age >= 60) return false;
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) return false;
    $opened = fstat($handle);
    $content = (string) fread($handle, 66);
    fclose($handle);
    if (!is_array($opened) || $opened['ino'] !== $file['ino'] || $opened['dev'] !== $file['dev']
        || !hash_equals($token."\n", $content)) return false;
    return @unlink($path); // the unlink is the claim: of two racing requests only one can succeed
}

/** The dashboard password from the account's private credentials file (both variants write it first). */
function pmssHermesRelayPassword(string $file): ?string
{
    clearstatcache(true, $file);
    $stat = @lstat($file);
    if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || $stat['uid'] !== posix_geteuid()
        || ($stat['mode'] & 0077) !== 0 || $stat['size'] > 2048) return null;
    $raw = (string) @file_get_contents($file, false, null, 0, 2049);
    return preg_match('/^HERMES_DASHBOARD_BASIC_AUTH_PASSWORD=([a-f0-9]{64})\n/', $raw, $match) === 1 ? $match[1] : null;
}

/** Hermes' own Set-Cookie values from a 200 password-login answer that carries a secure session cookie. */
function pmssHermesRelayLoginCookies(string $raw): ?array
{
    $end = strpos($raw, "\r\n\r\n");
    if ($end === false) return null;
    $lines = explode("\r\n", substr($raw, 0, $end));
    if (preg_match('/^HTTP\/1\.[01] 200(?: |$)/', (string) array_shift($lines)) !== 1) return null;
    $cookies = array();
    $session = false;
    foreach ($lines as $line) {
        if (stripos($line, 'set-cookie:') !== 0) continue;
        $cookie = trim(substr($line, 11));
        if ($cookie === '' || strpbrk($cookie, "\r\n\0") !== false) return null;
        $cookies[] = $cookie;
        if (preg_match('/^__Host-hermes_session_at=[^;\s]+;/', $cookie) === 1) $session = true;
    }
    return $session ? $cookies : null;
}

/** Exchange a spent token for a Hermes session; the password never leaves the account's loopback. */
function pmssHermesRelayLogin(string $token, string $credentials, string $user, int $port, ?callable $owner = null): string
{
    $deny = "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\nCache-Control: no-store\r\nConnection: close\r\n\r\n";
    if ($credentials === '' || preg_match('/^[a-z][a-z0-9]{0,31}$/D', $user) !== 1
        || !pmssHermesRelayTokenClaim(dirname($credentials).'/login-tokens', $token)) return $deny;
    $password = pmssHermesRelayPassword($credentials);
    $backend = $password === null ? false : pmssHermesRelayConnect($port, $owner);
    if ($backend === false) return $deny;
    $body = (string) json_encode(array('provider' => 'basic', 'username' => $user, 'password' => $password, 'next' => '/'));
    // Hermes names its cookies __Host- only for an https request; it trusts this header from loopback alone.
    $request = "POST /auth/password-login HTTP/1.1\r\nHost: 127.0.0.1\r\nX-Forwarded-Proto: https\r\n"
        ."Content-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body;
    stream_set_timeout($backend, 5);
    $deadline = time() + 5;
    $raw = '';
    $sent = @fwrite($backend, $request) === strlen($request);
    while ($sent && !feof($backend) && strlen($raw) < 65536 && time() <= $deadline) {
        $chunk = @fread($backend, 8192);
        if ($chunk === false || ($chunk === '' && stream_get_meta_data($backend)['timed_out'])) break;
        $raw .= $chunk;
    }
    fclose($backend);
    $cookies = pmssHermesRelayLoginCookies($raw);
    if ($cookies === null) return $deny;
    $response = "HTTP/1.1 204 No Content\r\n";
    foreach ($cookies as $cookie) $response .= 'Set-Cookie: '.$cookie."\r\n";
    return $response."Cache-Control: no-store\r\nConnection: close\r\n\r\n";
}

/**
 * Forward both directions; bytes a non-blocking write could not take wait in that stream's queue. With a
 * credentials file, a connection whose first request is the sign-in exchange is answered here, never forwarded.
 */
function pmssHermesRelayServe(string $socket, int $port, ?callable $owner = null, string $credentials = '', string $user = ''): int
{
    clearstatcache(true, dirname($socket));
    if ($port < 1024 || $port > 65535 || is_link(dirname($socket))
        || !is_dir(dirname($socket)) || (fileperms(dirname($socket)) & 0077) !== 0) return 1;
    if (file_exists($socket) || is_link($socket)) {
        if (!is_link($socket) && filetype($socket) === 'socket') @unlink($socket);
        else return 1;
    }
    $server = @stream_socket_server('unix://'.$socket, $error, $message);
    if ($server === false) return 1;
    if (!@chmod($socket, 0600)) { fclose($server); @unlink($socket); return 1; }
    $peers = array(); $streams = array(); $queue = array(); $ended = array();
    // Sign-in exchange: first bytes not yet classified, answers to close once sent, and both their time limits.
    $pending = array(); $finish = array(); $deadline = array();
    $close = static function (int $id) use (&$peers, &$streams, &$queue, &$ended, &$pending, &$finish, &$deadline): void {
        foreach (array($id, $peers[$id] ?? null) as $side) {
            if ($side === null || !isset($streams[$side])) continue;
            fclose($streams[$side]);
            unset($streams[$side], $peers[$side], $queue[$side], $ended[$side], $pending[$side], $finish[$side], $deadline[$side]);
        }
    };
    $pair = static function ($client, $backend) use (&$streams, &$peers): void {
        stream_set_blocking($client, false);
        stream_set_blocking($backend, false);
        $streams[(int) $client] = $client; $streams[(int) $backend] = $backend;
        $peers[(int) $client] = (int) $backend; $peers[(int) $backend] = (int) $client;
    };
    while (true) {
        $read = array($server); $write = array();
        foreach ($streams as $id => $stream) {
            if (isset($pending[$id])) { $read[] = $stream; continue; }
            if (isset($finish[$id])) { if (($queue[$id] ?? '') !== '') $write[] = $stream; continue; }
            // A stream is read only while its peer has nothing queued, so memory stays bounded.
            if (!isset($ended[$id]) && ($queue[$peers[$id]] ?? '') === '') $read[] = $stream;
            if (($queue[$id] ?? '') !== '') $write[] = $stream;
        }
        $except = null;
        if (@stream_select($read, $write, $except, $deadline === array() ? 30 : 1) === false) break;
        foreach ($write as $stream) {
            $id = (int) $stream;
            if (!isset($streams[$id])) continue;
            $sent = @fwrite($stream, $queue[$id]);
            if ($sent === false) { $close($id); continue; }
            $queue[$id] = (string) substr($queue[$id], $sent);
            if ($queue[$id] === '' && (isset($finish[$id]) || (isset($peers[$id]) && isset($ended[$peers[$id]])))) $close($id);
        }
        foreach ($read as $source) {
            if ($source === $server) {
                $client = @stream_socket_accept($server, 0);
                if ($client === false) continue;
                if ($credentials !== '') {
                    stream_set_blocking($client, false);
                    $streams[(int) $client] = $client;
                    $pending[(int) $client] = '';
                    $deadline[(int) $client] = time() + 10;
                    continue;
                }
                $backend = pmssHermesRelayConnect($port, $owner);
                if ($backend === false) { fclose($client); continue; }
                $pair($client, $backend);
                continue;
            }
            $id = (int) $source;
            if (!isset($streams[$id])) continue;
            $data = @fread($source, 16384);
            if ($data === false || $data === '') {
                if (!feof($source)) continue;
                if (isset($pending[$id])) { $close($id); continue; }
                $ended[$id] = true;
                if (($queue[$peers[$id]] ?? '') === '') $close($id);
                continue;
            }
            if (!isset($pending[$id])) {
                $queue[$peers[$id]] = ($queue[$peers[$id]] ?? '').$data;
                continue;
            }
            $pending[$id] .= $data;
            $step = pmssHermesRelayLoginRequest($pending[$id]);
            if ($step === null) continue;
            $buffered = $pending[$id];
            unset($pending[$id], $deadline[$id]);
            if ($step === false) {
                $backend = pmssHermesRelayConnect($port, $owner);
                if ($backend === false) { $close($id); continue; }
                $pair($source, $backend);
                $queue[(int) $backend] = $buffered;
                continue;
            }
            $queue[$id] = pmssHermesRelayLogin($step, $credentials, $user, $port, $owner);
            $finish[$id] = true;
            $deadline[$id] = time() + 10;
        }
        $now = time();
        foreach ($deadline as $id => $limit) if ($now > $limit) $close($id);
    }
    fclose($server);
    return 0;
}

/** A PID is actionable only when it still names this account's relay script. */
function pmssHermesRelayStop(string $pidFile, string $socket): void
{
    clearstatcache(true, $pidFile);
    $raw = is_file($pidFile) && !is_link($pidFile) ? trim((string) @file_get_contents($pidFile)) : '';
    if (preg_match('/^[1-9][0-9]*$/D', $raw)) {
        $proc = '/proc/'.$raw;
        $cmd = @file_get_contents($proc.'/cmdline');
        if (@fileowner($proc) === posix_geteuid() && is_string($cmd)
            && strpos($cmd, 'pmssHermesRelay.php') !== false && strpos($cmd, $socket) !== false)
            @posix_kill((int) $raw, SIGTERM);
    }
}

/** A relay started before the sign-in exchange existed has no credentials argument and is replaced on the next start. */
function pmssHermesRelayCurrent(string $pidFile, string $socket, string $credentials): bool
{
    clearstatcache(true, $pidFile);
    $raw = is_file($pidFile) && !is_link($pidFile) ? trim((string) @file_get_contents($pidFile)) : '';
    if (!preg_match('/^[1-9][0-9]*$/D', $raw) || @fileowner('/proc/'.$raw) !== posix_geteuid()) return false;
    $arguments = explode("\0", (string) @file_get_contents('/proc/'.$raw.'/cmdline'));
    $serve = array_search('serve', $arguments, true);
    return is_int($serve) && $serve > 0 && substr($arguments[$serve - 1], -20) === '/pmssHermesRelay.php'
        && ($arguments[$serve + 1] ?? '') === $socket && ($arguments[$serve + 3] ?? '') === $credentials;
}

function pmssHermesRelayStart(string $home, string $pidFile, string $socket, int $port, string $credentials = ''): bool
{
    pmssHermesRelayStop($pidFile, $socket);
    $directory = dirname($socket);
    if (is_link($directory) || !is_dir($directory) || fileowner($directory) !== posix_geteuid()
        || !@chmod($directory, 0700)) return false;
    $script = $home.'/bin/pmssHermesRelay.php';
    if (!is_file($script) || is_link($script)) return false;
    $command = '/usr/bin/setsid nohup /usr/bin/php '.escapeshellarg($script).' serve '.escapeshellarg($socket).' '
        .escapeshellarg((string) $port).($credentials !== '' ? ' '.escapeshellarg($credentials) : '')
        .' </dev/null >/dev/null 2>&1 & echo $!';
    $rows = array(); exec($command, $rows, $rc);
    $pid = trim((string) ($rows[0] ?? ''));
    if ($rc !== 0 || !preg_match('/^[1-9][0-9]*$/D', $pid)) return false;
    if (@file_put_contents($pidFile, $pid."\n") !== strlen($pid."\n")) {
        @posix_kill((int) $pid, SIGTERM);
        return false;
    }
    for ($i = 0; $i < 20; ++$i) {
        if (@filetype($socket) === 'socket' && pmssHermesRelayReady($socket)) return true;
        usleep(100000);
    }
    pmssHermesRelayStop($pidFile, $socket);
    return false;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require_once __DIR__.'/pmssAccountAgent.php';
    try {
        $home = pmssAgentHome();
        $socket = (string) ($argv[2] ?? '');
        $credentials = (string) ($argv[4] ?? '');
        if (($argv[1] ?? '') !== 'serve' || strpos($socket, $home.'/') !== 0) exit(1);
        if ($credentials !== '' && (strpos($credentials, $home.'/') !== 0 || strpos($credentials, '/../') !== false)) exit(1);
        exit(pmssHermesRelayServe($socket, (int) ($argv[3] ?? 0), null, $credentials, basename($home)));
    } catch (Throwable $error) { exit(1); }
}
