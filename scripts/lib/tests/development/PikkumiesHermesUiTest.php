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
