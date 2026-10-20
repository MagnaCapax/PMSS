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

function pmssHermesRelayServe(string $socket, int $port): int
{
    if ($port < 1024 || $port > 65535 || is_link(dirname($socket))
        || !is_dir(dirname($socket)) || (fileperms(dirname($socket)) & 0077) !== 0) return 1;
    if (file_exists($socket) || is_link($socket)) {
        if (!is_link($socket) && filetype($socket) === 'socket') @unlink($socket);
        else return 1;
    }
    $server = @stream_socket_server('unix://'.$socket, $error, $message);
    if ($server === false) return 1;
    if (!@chmod($socket, 0600)) { fclose($server); @unlink($socket); return 1; }
    $peers = array();
    while (true) {
        $read = array_merge(array($server), array_values($peers));
        $write = null; $except = null;
        if (@stream_select($read, $write, $except, 30) === false) break;
        foreach ($read as $source) {
            if ($source === $server) {
                $client = @stream_socket_accept($server, 0);
                if ($client === false) continue;
                $backend = pmssHermesRelayConnect($port);
                if ($backend === false) { fclose($client); continue; }
                stream_set_blocking($client, false);
                stream_set_blocking($backend, false);
                $peers[(int) $client] = $backend;
                $peers[(int) $backend] = $client;
                continue;
            }
            $target = $peers[(int) $source] ?? null;
            if ($target === null) continue;
            $data = @fread($source, 16384);
            if ($data === false || $data === '') {
                if (!feof($source)) continue;
                unset($peers[(int) $source], $peers[(int) $target]);
                fclose($source); fclose($target);
                continue;
            }
            while ($data !== '') {
                $sent = @fwrite($target, $data);
                if ($sent === false || $sent === 0) break;
                $data = substr($data, $sent);
            }
        }
    }
    fclose($server);
    return 0;
}

/** A PID is actionable only when it still names this account's relay script. */
function pmssHermesRelayStop(string $pidFile, string $socket): void
{
    $raw = is_file($pidFile) && !is_link($pidFile) ? trim((string) @file_get_contents($pidFile)) : '';
    if (preg_match('/^[1-9][0-9]*$/D', $raw)) {
        $proc = '/proc/'.$raw;
        $cmd = @file_get_contents($proc.'/cmdline');
        if (@fileowner($proc) === posix_geteuid() && is_string($cmd)
            && strpos($cmd, 'pmssHermesRelay.php') !== false && strpos($cmd, $socket) !== false)
            @posix_kill((int) $raw, SIGTERM);
    }
}

function pmssHermesRelayStart(string $home, string $pidFile, string $socket, int $port): bool
{
    pmssHermesRelayStop($pidFile, $socket);
    $directory = dirname($socket);
    if (is_link($directory) || !is_dir($directory) || fileowner($directory) !== posix_geteuid()
        || !@chmod($directory, 0700)) return false;
    $script = $home.'/bin/pmssHermesRelay.php';
    if (!is_file($script) || is_link($script)) return false;
    $command = 'nohup /usr/bin/php '.escapeshellarg($script).' serve '.escapeshellarg($socket).' '
        .escapeshellarg((string) $port).' </dev/null >/dev/null 2>&1 & echo $!';
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
        if (($argv[1] ?? '') !== 'serve' || strpos($socket, $home.'/') !== 0) exit(1);
        exit(pmssHermesRelayServe($socket, (int) ($argv[3] ?? 0)));
    } catch (Throwable $error) { exit(1); }
}
