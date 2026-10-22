<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/bin/pikkumiesHermesUi.php';
require_once dirname(__DIR__, 2).'/nginxUserHosts.php';
require_once dirname(__DIR__, 2).'/nginxConfig/pikkumiesUi.php';
require_once dirname(__DIR__, 2).'/lighttpd/pikkumiesUi.php';

final class PikkumiesHermesUiTest extends TestCase
{
    public function testPrivateCredentialsAndRotation(): void
    {
        $home = $this->pmssMakeTempDir('pmss-pikkumies-hermes-', 0700);
        $paths = \pmssPikkumiesHermesPaths($home);
        $this->assertTrue(\pmssPikkumiesHermesWrite($paths, str_repeat('a', 64), str_repeat('b', 64)));
        $this->assertSame(0600, fileperms($paths['credentials']) & 0777);
        $this->assertSame([str_repeat('a', 64), str_repeat('b', 64)], \pmssPikkumiesHermesCredentials($paths));
        $this->assertTrue(\pmssPikkumiesHermesRotate($paths));
        $rotated = \pmssPikkumiesHermesCredentials($paths);
        $this->assertFalse($rotated[0] === str_repeat('a', 64));
        $this->assertFalse($rotated[1] === str_repeat('b', 64));
        chmod($paths['credentials'], 0644);
        $this->assertSame(null, \pmssPikkumiesHermesCredentials($paths));
    }

    public function testChildCommandHasCleanEnvironmentAndNoSecretArgv(): void
    {
        $home = $this->pmssMakeTempDir('pmss-pikkumies-hermes-', 0700);
        $paths = \pmssPikkumiesHermesPaths($home);
        $this->assertTrue(\pmssPikkumiesHermesWrite($paths, str_repeat('a', 64), str_repeat('b', 64)));
        $command = \pmssPikkumiesHermesCommand($paths, 'https://0123456789abcdef.mcx.fi:845', 24000);
        $this->assertStringContainsAllStrings(['/usr/bin/env -i', 'HERMES_DASHBOARD_PUBLIC_URL=',
            'run dashboard --host 127.0.0.1', $paths['credentials']], $command);
        // Its own session: php-cgi signals its whole process group when it recycles (live QA 2026-10-21).
        $this->assertSame(0, strpos($command, '/usr/bin/setsid nohup '), $command);
        $this->assertFalse(strpos($command, str_repeat('a', 64)) !== false);
        $this->assertFalse(strpos($command, str_repeat('b', 64)) !== false);
        $this->assertFalse(\pmssPikkumiesHermesStart($paths, 'http://0123456789abcdef.mcx.fi:845'));
    }

    public function testPortBUsesPrivateOriginAndAuthGate(): void
    {
        $live = $this->pmssMakeTempDir('pmss-pikkumies-b-cert-', 0700);
        mkdir($live.'/uitester.node.example.com');
        file_put_contents($live.'/uitester.node.example.com/fullchain.pem', 'fixture');
        file_put_contents($live.'/uitester.node.example.com/privkey.pem', 'fixture');
        $block = \pmssPikkumiesHermesNginxBlock('uitester', 'node.example.com',
            '0123456789abcdef.mcx.fi', 12345, $live);
        $this->assertStringContainsAllStrings(['listen 845 ssl;', 'server_name 0123456789abcdef.mcx.fi;',
            'auth_request /__pmss-b-auth;', 'location = /api/cron/fire { return 404; }',
            'same-origin|none', "frame-ancestors 'none'",
            'pmss_ui_b_noargs', 'proxy_set_header X-Forwarded-Proto https;'], $block);
        $this->assertFalse(strpos($block, '$args') !== false);
        $this->assertFalse(strpos($block, 'server_name uitester') !== false);
        $this->assertStringContainsString('listen 845 ssl default_server;',
            \pmssPikkumiesHermesDefaultNginxBlock("    ssl_certificate /safe/fullchain.pem;\n"));
        $this->pmssAssertRepoFileContainsString('etc/seedbox/config/template.nginx-conf',
            'log_format pmss_ui_b_noargs');
        $lighttpd = \pmssLighttpdPikkumiesHermesBlock('uitester', 24000);
        $this->assertStringContainsAllStrings(['^/__pmss-ui-b/', '"socket" => "/home/uitester/.mcxPikkumies/pikkumies/local/ui/hermes/ui.sock"',
            '"Authorization" => ""', 'X-Forwarded-Proto'], $lighttpd);
        $this->assertSame('', \pmssLighttpdPikkumiesHermesBlock('../uitester', 24000));
    }

    public function testRotationStopsRecordedChildBeforeReadingInvalidCredentials(): void
    {
        $home = $this->pmssMakeTempDir('pmss-b-rotation-', 0700);
        $paths = \pmssPikkumiesHermesPaths($home);
        $this->assertTrue(\pmssPikkumiesHermesWrite($paths, str_repeat('a', 64), str_repeat('b', 64)));
        $process = proc_open('exec '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('sleep(30);')
            .' dashboard hermes', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertTrue(is_resource($process));
        $pid = proc_get_status($process)['pid'];
        fclose($pipes[0]); fclose($pipes[1]); fclose($pipes[2]);
        file_put_contents($paths['pid'], $pid."\n");
        usleep(100000);
        chmod($paths['credentials'], 0644);
        try {
            $this->assertFalse(\pmssPikkumiesHermesRotate($paths));
            usleep(100000);
            $this->assertFalse(strpos((string) @file_get_contents('/proc/'.$pid.'/cmdline'), 'dashboard') !== false);
        } finally {
            @posix_kill($pid, SIGKILL);
            proc_close($process);
        }
    }

    public function testRelayRefusesUnownedListener(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertTrue($server !== false);
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        try {
            $foreign = static function (): bool { return false; };
            $owned = static function (): bool { return true; };
            $this->assertFalse(\pmssHermesRelayListenerOwned($port, $foreign));
            $this->assertFalse(\pmssHermesRelayConnect($port, $foreign));
            $peer = \pmssHermesRelayConnect($port, $owned);
            $this->assertTrue($peer !== false);
            fclose($peer);
        } finally { fclose($server); }
    }

    public function testRelayForwardsLargeResponseToSlowReader(): void
    {
        $socket = $this->pmssMakeTempDir('pmss-relay-', 0700).'/hermes.sock';
        $size = 3 * 1024 * 1024;
        $body = '';
        for ($i = 0; strlen($body) < $size; $i++) $body .= sprintf('%08d|', $i);
        $body = substr($body, 0, $size);
        $backend = proc_open('exec '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg(
            '$s = stream_socket_server("tcp://127.0.0.1:0"); echo substr(strrchr(stream_socket_get_name($s, false), ":"), 1), "\n";'
            .' fflush(STDOUT); $c = stream_socket_accept($s, 20); while (($l = fgets($c)) !== false && $l !== "\r\n");'
            .' $b = ""; for ($i = 0; strlen($b) < '.$size.'; $i++) $b .= sprintf("%08d|", $i);'
            .' $out = "HTTP/1.1 200 OK\r\nContent-Length: '.$size.'\r\n\r\n".substr($b, 0, '.$size.');'
            .' while ($out !== "") { $n = fwrite($c, $out); if (!$n) break; $out = (string) substr($out, $n); } fclose($c);'),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $backendPipes);
        $this->assertTrue(is_resource($backend));
        $port = (int) trim((string) fgets($backendPipes[1]));
        $relay = proc_open('exec '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg(
            'require '.var_export(dirname(__DIR__, 4).'/etc/skel/bin/pmssHermesRelay.php', true).';'
            .' exit(pmssHermesRelayServe('.var_export($socket, true).', '.$port.', static function (): bool { return true; }));'),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $relayPipes);
        $this->assertTrue(is_resource($relay));
        try {
            for ($i = 0; $i < 50 && @filetype($socket) !== 'socket'; ++$i) usleep(100000);
            $peer = stream_socket_client('unix://'.$socket, $error, $message, 5);
            $this->assertTrue($peer !== false, 'relay socket did not accept');
            stream_set_timeout($peer, 20);
            fwrite($peer, "GET /big HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");
            usleep(500000); // let both socket buffers fill before reading
            $received = '';
            while (!feof($peer)) {
                $chunk = fread($peer, 8192);
                if ($chunk === false || ($chunk === '' && stream_get_meta_data($peer)['timed_out'])) break;
                $received .= $chunk;
            }
            fclose($peer);
            $split = strpos($received, "\r\n\r\n");
            $this->assertTrue($split !== false, 'no response headers');
            $received = (string) substr($received, $split + 4);
            $this->assertSame($size, strlen($received));
            $this->assertSame(sha1($body), sha1($received));
        } finally {
            proc_terminate($relay); proc_terminate($backend);
            foreach (array_merge($relayPipes, $backendPipes) as $pipe) fclose($pipe);
            proc_close($relay); proc_close($backend);
        }
    }

    /** One private launcher token as pikkumiesOpen.php writes it. */
    private function loginToken(string $directory, int $age = 0): string
    {
        if (!is_dir($directory)) mkdir($directory, 0700, true);
        $token = bin2hex(random_bytes(32));
        file_put_contents($directory.'/'.$token, $token."\n");
        chmod($directory.'/'.$token, 0600);
        if ($age > 0) touch($directory.'/'.$token, time() - $age);
        clearstatcache();
        return $token;
    }

    /** A loopback Hermes stand-in: logs each request and answers the password login with $status. */
    private function fakeHermes(string $log, int $status): array
    {
        $cookies = $status === 200 ? '"set-cookie: __Host-hermes_session_at=\"at=\"; HttpOnly; Max-Age=43200; Path=/; SameSite=lax; Secure\r\n'
            .'set-cookie: __Host-hermes_session_rt=rt; HttpOnly; Max-Age=2592000; Path=/; SameSite=lax; Secure\r\n"' : '""';
        $script = '$s = stream_socket_server("tcp://127.0.0.1:0"); echo substr(strrchr(stream_socket_get_name($s, false), ":"), 1), "\n"; fflush(STDOUT);'
            .' while ($c = @stream_socket_accept($s, 30)) { $r = "";'
            .' while (($l = fgets($c)) !== false) { $r .= $l; if ($l === "\r\n") break; }'
            .' $n = preg_match("/^content-length: *([0-9]+)/mi", $r, $m) ? (int) $m[1] : 0;'
            .' $b = ""; while (strlen($b) < $n && !feof($c)) $b .= fread($c, $n - strlen($b));'
            .' file_put_contents('.var_export($log, true).', $r.$b."\n--END--\n", FILE_APPEND);'
            .' $out = strpos($r, "POST /auth/password-login ") === 0'
            .' ? "HTTP/1.1 '.$status.' X\r\n".'.$cookies.'."Content-Length: 11\r\nConnection: close\r\n\r\n{\"ok\":true}"'
            .' : "HTTP/1.1 200 OK\r\nContent-Length: 5\r\nConnection: close\r\n\r\nplain";'
            .' fwrite($c, $out); fclose($c); }';
        $process = proc_open('exec '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertTrue(is_resource($process));
        return [$process, $pipes, (int) trim((string) fgets($pipes[1]))];
    }

    private function startRelay(string $socket, int $port, string $credentials): array
    {
        $process = proc_open('exec '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg(
            'require '.var_export(dirname(__DIR__, 4).'/etc/skel/bin/pmssHermesRelay.php', true).';'
            .' exit(pmssHermesRelayServe('.var_export($socket, true).', '.$port.', static function (): bool { return true; }, '
            .var_export($credentials, true).', "exampleuser"));'),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertTrue(is_resource($process));
        for ($i = 0; $i < 50 && @filetype($socket) !== 'socket'; ++$i) usleep(100000);
        return [$process, $pipes];
    }

    private function relayRequest(string $socket, string $request): string
    {
        $peer = stream_socket_client('unix://'.$socket, $error, $message, 5);
        $this->assertTrue($peer !== false, 'relay socket did not accept');
        stream_set_timeout($peer, 10);
        fwrite($peer, $request);
        $received = '';
        while (!feof($peer)) {
            $chunk = fread($peer, 8192);
            if ($chunk === false || ($chunk === '' && stream_get_meta_data($peer)['timed_out'])) break;
            $received .= $chunk;
        }
        fclose($peer);
        return $received;
    }

    private static function signInRequest(string $body): string
    {
        return "POST /__pmss-session HTTP/1.0\r\nHost: 0123456789abcdef.mcx.fi\r\nContent-Type: text/plain\r\n"
            ."Content-Length: ".strlen($body)."\r\n\r\n".$body;
    }

    private function stopAll(array $processes): void
    {
        foreach ($processes as [$process, $pipes]) {
            proc_terminate($process);
            foreach ($pipes as $pipe) fclose($pipe);
            proc_close($process);
        }
    }

    public function testRelaySignInSpendsOneTokenAndForwardsEverythingElseUnchanged(): void
    {
        $home = $this->pmssMakeTempDir('pmss-relay-login-', 0700);
        // Short paths: a Unix socket path is limited to 108 bytes wherever the test temp root is.
        $paths = ['base' => $home.'/h', 'credentials' => $home.'/h/credentials', 'socket' => $home.'/h/ui.sock'];
        $this->assertTrue(\pmssPikkumiesHermesWrite($paths, str_repeat('a', 64), str_repeat('b', 64)));
        $log = $home.'/hermes.log';
        [$backend, $backendPipes, $port] = $this->fakeHermes($log, 200);
        [$relay, $relayPipes] = $this->startRelay($paths['socket'], $port, $paths['credentials']);
        try {
            $token = $this->loginToken($paths['base'].'/login-tokens');
            $answer = $this->relayRequest($paths['socket'], self::signInRequest($token));
            $this->assertSame(0, strpos($answer, "HTTP/1.1 204 No Content\r\n"), $answer);
            $this->assertStringContainsAllStrings([
                "\r\nSet-Cookie: __Host-hermes_session_at=\"at=\"; HttpOnly; Max-Age=43200; Path=/; SameSite=lax; Secure\r\n",
                "\r\nSet-Cookie: __Host-hermes_session_rt=rt; HttpOnly; Max-Age=2592000; Path=/; SameSite=lax; Secure\r\n",
                "\r\nCache-Control: no-store\r\n"], $answer);
            $this->assertFalse(strpos($answer, str_repeat('a', 64)) !== false, 'the password never reaches the browser');
            $this->assertFalse(file_exists($paths['base'].'/login-tokens/'.$token), 'the token is spent');
            $this->assertStringContainsAllStrings(["POST /auth/password-login HTTP/1.1\r\n", "\r\nHost: 127.0.0.1\r\n",
                "\r\nX-Forwarded-Proto: https\r\n", "\r\nContent-Type: application/json\r\n",
                '{"provider":"basic","username":"exampleuser","password":"'.str_repeat('a', 64).'","next":"\/"}'],
                (string) file_get_contents($log));
            // The same token again, malformed bodies, and a body that is not a token: nothing reaches Hermes.
            foreach ([$token, strtoupper($token), substr($token, 1), '', str_repeat('g', 64)] as $body) {
                $this->assertSame(0, strpos($this->relayRequest($paths['socket'], self::signInRequest($body)),
                    "HTTP/1.1 403 Forbidden\r\n"), $body);
            }
            $chunked = "POST /__pmss-session HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n40\r\n".$this->loginToken($paths['base'].'/login-tokens')."\r\n0\r\n\r\n";
            $this->assertSame(0, strpos($this->relayRequest($paths['socket'], $chunked), "HTTP/1.1 403 Forbidden\r\n"));
            $this->assertSame(1, substr_count((string) file_get_contents($log), '/auth/password-login'));
            $this->assertFalse(strpos((string) file_get_contents($log), '__pmss-session') !== false);
            // Any other request is forwarded byte for byte, including look-alike paths.
            foreach (["GET /api/status?x=1 HTTP/1.1\r\nHost: 127.0.0.1\r\nX-Custom: kept\r\n\r\n",
                "POST /__pmss-session?next=/ HTTP/1.1\r\nHost: 127.0.0.1\r\nContent-Length: 2\r\n\r\nab"] as $request) {
                $answer = $this->relayRequest($paths['socket'], $request);
                $this->assertSame("HTTP/1.1 200 OK\r\nContent-Length: 5\r\nConnection: close\r\n\r\nplain", $answer);
                $this->assertSame(1, substr_count((string) file_get_contents($log), $request."\n--END--\n"), $request);
            }
        } finally {
            $this->stopAll([[$relay, $relayPipes], [$backend, $backendPipes]]);
        }
    }

    public function testRelaySignInFailsClosedWhenHermesRefusesTheLogin(): void
    {
        $home = $this->pmssMakeTempDir('pmss-relay-refused-', 0700);
        // Short paths: a Unix socket path is limited to 108 bytes wherever the test temp root is.
        $paths = ['base' => $home.'/h', 'credentials' => $home.'/h/credentials', 'socket' => $home.'/h/ui.sock'];
        $this->assertTrue(\pmssPikkumiesHermesWrite($paths, str_repeat('a', 64), str_repeat('b', 64)));
        $log = $home.'/hermes.log';
        [$backend, $backendPipes, $port] = $this->fakeHermes($log, 401);
        [$relay, $relayPipes] = $this->startRelay($paths['socket'], $port, $paths['credentials']);
        try {
            $token = $this->loginToken($paths['base'].'/login-tokens');
            $answer = $this->relayRequest($paths['socket'], self::signInRequest($token));
            $this->assertSame("HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\nCache-Control: no-store\r\nConnection: close\r\n\r\n", $answer);
            $this->assertSame(1, substr_count((string) file_get_contents($log), '/auth/password-login'));
            $this->assertFalse(file_exists($paths['base'].'/login-tokens/'.$token), 'a refused login still spends the token');
        } finally {
            $this->stopAll([[$relay, $relayPipes], [$backend, $backendPipes]]);
        }
    }

    public function testSignInTokenMustBeAFreshPrivateSingleLinkFile(): void
    {
        $directory = $this->pmssMakeTempDir('pmss-relay-tokens-', 0700);
        $valid = $this->loginToken($directory);
        $this->assertFalse(\pmssHermesRelayTokenClaim($directory, strtoupper($valid)));
        $this->assertTrue(\pmssHermesRelayTokenClaim($directory, $valid));
        $this->assertFalse(\pmssHermesRelayTokenClaim($directory, $valid), 'reused');
        $expired = $this->loginToken($directory, 61);
        $this->assertFalse(\pmssHermesRelayTokenClaim($directory, $expired), 'expired');
        $outside = $this->pmssMakeTempDir('pmss-relay-outside-', 0700);
        $linked = bin2hex(random_bytes(32));
        file_put_contents($outside.'/'.$linked, $linked."\n");
        chmod($outside.'/'.$linked, 0600);
        symlink($outside.'/'.$linked, $directory.'/'.$linked);
        $this->assertFalse(\pmssHermesRelayTokenClaim($directory, $linked), 'symlinked');
        $this->assertTrue(is_file($outside.'/'.$linked));
        $hard = $this->loginToken($directory);
        link($directory.'/'.$hard, $directory.'/second-name');
        $this->assertFalse(\pmssHermesRelayTokenClaim($directory, $hard), 'hard-linked');
        $readable = $this->loginToken($directory);
        chmod($directory.'/'.$readable, 0644);
        $this->assertFalse(\pmssHermesRelayTokenClaim($directory, $readable), 'group or world readable');
        $mismatch = $this->loginToken($directory);
        file_put_contents($directory.'/'.$mismatch, str_repeat('0', 64)."\n");
        $this->assertFalse(\pmssHermesRelayTokenClaim($directory, $mismatch), 'content mismatch');
        $open = $this->loginToken($directory);
        chmod($directory, 0755);
        $this->assertFalse(\pmssHermesRelayTokenClaim($directory, $open), 'shared token directory');
        chmod($directory, 0700);
        $this->assertTrue(\pmssHermesRelayTokenClaim($directory, $open));
    }

    public function testSignInRequestClassificationDecidesOnTheFirstDifferentByte(): void
    {
        $this->assertFalse(\pmssHermesRelayLoginRequest('G'));
        $this->assertFalse(\pmssHermesRelayLoginRequest("POST /__pmss-session?x HTTP/1.1\r\n\r\n"));
        $this->assertFalse(\pmssHermesRelayLoginRequest("POST /__pmss-sessions HTTP/1.1\r\n\r\n"));
        $this->assertSame(null, \pmssHermesRelayLoginRequest('POST /__pmss-sess'));
        $this->assertSame(null, \pmssHermesRelayLoginRequest("POST /__pmss-session HTTP/1.1\r\nContent-Length: 4\r\n\r\nab"));
        $this->assertSame('abcd', \pmssHermesRelayLoginRequest("POST /__pmss-session HTTP/1.1\r\nContent-Length: 4\r\n\r\nabcd"));
        $this->assertSame('', \pmssHermesRelayLoginRequest("POST /__pmss-session HTTP/1.1\r\nContent-Length: 4\r\n\r\nabcde"));
        $this->assertSame('', \pmssHermesRelayLoginRequest("POST /__pmss-session HTTP/1.1\r\n\r\n"));
        $this->assertSame('', \pmssHermesRelayLoginRequest("POST /__pmss-session HTTP/1.1\r\nContent-Length: 999\r\n\r\n"));
        $this->assertSame('', \pmssHermesRelayLoginRequest("POST /__pmss-session HTTP/1.1\r\nContent-Length: 1\r\nContent-Length: 1\r\n\r\na"));
        $this->assertSame('', \pmssHermesRelayLoginRequest('POST /__pmss-session HTTP/1.1'.str_repeat('x', 9000)));
    }

    public function testRelayWithoutTheCredentialsArgumentIsNotCurrent(): void
    {
        $directory = $this->pmssMakeTempDir('pmss-relay-current-', 0700);
        $process = proc_open('exec '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('sleep(30);')
            .' /home/exampleuser/bin/pmssHermesRelay.php serve '.escapeshellarg($directory.'/ui.sock').' 24000 '
            .escapeshellarg($directory.'/credentials'), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertTrue(is_resource($process));
        $pid = proc_get_status($process)['pid'];
        file_put_contents($directory.'/relay.pid', $pid."\n");
        usleep(200000);
        try {
            $this->assertTrue(\pmssHermesRelayCurrent($directory.'/relay.pid', $directory.'/ui.sock', $directory.'/credentials'));
            $this->assertFalse(\pmssHermesRelayCurrent($directory.'/relay.pid', $directory.'/ui.sock', $directory.'/other'));
            $this->assertFalse(\pmssHermesRelayCurrent($directory.'/relay.pid', $directory.'/other.sock', $directory.'/credentials'));
            $this->assertFalse(\pmssHermesRelayCurrent($directory.'/missing.pid', $directory.'/ui.sock', $directory.'/credentials'));
        } finally {
            $this->stopAll([[$process, $pipes]]);
        }
        $start = $this->pmssReadRepoFile('etc/skel/bin/pikkumiesHermesRuntime.php')
            .$this->pmssReadRepoFile('etc/skel/bin/pmssHermesRuntime.php');
        $this->assertSame(0, substr_count($start, "\$paths['socket'], \$port)"), 'every relay start passes its credentials file');
    }

    public function testMissingListenerToolStopsRecordedChild(): void
    {
        $home = $this->pmssMakeTempDir('pmss-b-tool-', 0700);
        $paths = \pmssPikkumiesHermesPaths($home);
        mkdir($paths['base'], 0700, true);
        file_put_contents($paths['port'], "24000\n");
        $process = proc_open('exec '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('sleep(30);')
            .' dashboard hermes', [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertTrue(is_resource($process));
        $pid = proc_get_status($process)['pid'];
        fclose($pipes[0]); fclose($pipes[1]); fclose($pipes[2]);
        file_put_contents($paths['pid'], $pid."\n");
        usleep(100000);
        $previous = getenv('PATH');
        putenv('PATH=/does-not-exist');
        try { $this->assertFalse(\pmssPikkumiesHermesEnsure($paths)); }
        finally { $previous === false ? putenv('PATH') : putenv('PATH='.$previous); }
        usleep(100000);
        $this->assertFalse(strpos((string) @file_get_contents('/proc/'.$pid.'/cmdline'), 'dashboard') !== false);
        @posix_kill($pid, SIGKILL);
        proc_close($process);
    }

    public function testDashboardLoginProbeDistinguishesDeniedAndOpenResponses(): void
    {
        foreach ([401 => true, 200 => false] as $status => $expected) {
            $script = '$server=stream_socket_server("tcp://127.0.0.1:0"); '
                .'echo stream_socket_get_name($server,false),"\n"; fflush(STDOUT); '
                .'$peer=stream_socket_accept($server,5); if ($peer) { fread($peer,4096); '
                .'fwrite($peer,"HTTP/1.1 '.$status.' Status\r\nContent-Length: 0\r\nConnection: close\r\n\r\n"); '
                .'fclose($peer); } fclose($server);';
            $process = proc_open(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertTrue(is_resource($process));
            fclose($pipes[0]);
            $address = fgets($pipes[1]);
            $this->assertTrue(is_string($address));
            $port = (int) substr(strrchr(trim($address), ':'), 1);
            $this->assertSame($expected, \pmssPikkumiesHermesGate($port));
            fclose($pipes[1]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($process));
        }
    }

    public function testGeneratedPortBConfigParsesWhenNginxIsAvailable(): void
    {
        $nginx = trim((string) @shell_exec('command -v nginx 2>/dev/null'));
        $openssl = trim((string) @shell_exec('command -v openssl 2>/dev/null'));
        if ($nginx === '' || $openssl === '') throw new SkipTest('nginx or openssl is unavailable');
        $root = $this->pmssMakeTempDir('pmss-b-nginx-', 0700);
        mkdir($root.'/uitester.node.example.com');
        $cert = $root.'/uitester.node.example.com/fullchain.pem';
        $key = $root.'/uitester.node.example.com/privkey.pem';
        exec(escapeshellarg($openssl).' req -x509 -newkey rsa:2048 -nodes -subj /CN=uitester.node.example.com'
            .' -keyout '.escapeshellarg($key).' -out '.escapeshellarg($cert).' -days 1 2>/dev/null', $rows, $rc);
        $this->assertSame(0, $rc);
        $block = \pmssPikkumiesHermesNginxBlock('uitester', 'node.example.com',
            '0123456789abcdef.mcx.fi', 12345, $root);
        $block .= "\n".\pmssHermesUiNginxBlock('uitester', 'node.example.com',
            '0123456789abcdef.mcx.fi', 12345, $root);
        $block = preg_replace('/^    (?:include|ssl_dhparam) \/etc\/letsencrypt\/[^;]+;\n/m', '', $block);
        $block = str_replace('/var/log/nginx/access.log', $root.'/access.log', $block);
        // Every path nginx -t opens stays under $root, so the check also runs as an unprivileged user.
        $config = "pid {$root}/nginx.pid; error_log {$root}/error.log; events {} http { access_log {$root}/access.log;\n"
            ."client_body_temp_path {$root}/tmp_body; proxy_temp_path {$root}/tmp_proxy; fastcgi_temp_path {$root}/tmp_fcgi;\n"
            ."uwsgi_temp_path {$root}/tmp_uwsgi; scgi_temp_path {$root}/tmp_scgi; log_format pmss_ui_b_noargs '\$uri';\n"
            ."log_format pmss_ui_noargs '\$uri'; limit_conn_zone \$binary_remote_addr zone=addr:10m;\n"
            .\pmssPikkumiesHermesDefaultNginxBlock('    ssl_certificate '.escapeshellarg($cert).";\n"
                .'    ssl_certificate_key '.escapeshellarg($key).";\n")
            .\pmssHermesUiDefaultNginxBlock('    ssl_certificate '.escapeshellarg($cert).";\n"
                .'    ssl_certificate_key '.escapeshellarg($key).";\n")
            .$block."\n}";
        // nginx -t binds every listen port; an unprivileged user cannot bind 844-846, so test them 20000 higher.
        $config = preg_replace_callback('/\blisten (84[4-6])\b/', static function (array $m): string {
            return 'listen '.((int) $m[1] + 20000);
        }, $config);
        file_put_contents($root.'/nginx.conf', $config);
        exec(escapeshellarg($nginx).' -t -p '.escapeshellarg($root.'/').' -c '.escapeshellarg($root.'/nginx.conf').' 2>&1', $rows, $rc);
        $this->assertSame(0, $rc, implode("\n", $rows));
    }

    public function testRootInstallerDoesNotExecuteAccountBinary(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/update/apps/pikkumiesAgentboard.php');
        $this->assertStringContainsString('--no-same-owner --no-same-permissions', $source);
        $this->assertFalse(strpos($source, 'exec($root.\'/agentboard') !== false);
        $this->assertFalse(strpos($source, 'passthru($root.\'/agentboard') !== false);
    }
}
