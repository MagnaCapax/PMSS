<?php
/** Exercise the managed WebDAV dotfile policy with authenticated lighttpd requests. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class WebdavDotfileResponseTest extends TestCase
{
    /** Optional loopback fixture; all writes stay in a tracked private temporary directory. */
    public function testDotfileRequestsAndDestinationsAreDeniedByTheServer(): void
    {
        $servers = array_values(array_filter(['/usr/sbin/lighttpd', '/usr/local/sbin/lighttpd', '/usr/bin/lighttpd'], 'is_executable'));
        $clients = array_values(array_filter(['/usr/bin/curl', '/usr/local/bin/curl'], 'is_executable'));
        $modules = array_merge(glob('/usr/lib/lighttpd/mod_webdav.so') ?: [], glob('/usr/lib/*/lighttpd/mod_webdav.so') ?: []);
        if (!$servers || !$clients || !$modules || !is_file(dirname($modules[0]).'/mod_authn_file.so')) {
            throw new SkipTest('Needs lighttpd, mod_webdav, mod_authn_file and curl on the testing machine');
        }

        $dir = $this->pmssMakeTempDir('pmss-webdav-dotfiles-', 0700);
        $files = ['.probe', '.lighttpd/state', 'www/public/.hidden', 'data/.nested/file.txt', 'data/control.txt'];
        foreach ($files as $file) {
            $this->pmssWriteFile($dir.'/home/'.$file, "fixture\n", 0700);
        }
        // Synthetic credentials stay in private files and out of process arguments.
        $password = bin2hex(random_bytes(16));
        $this->pmssWriteFile($dir.'/htpasswd', 'fixtur01:'.password_hash($password, PASSWORD_BCRYPT)."\n");
        $this->pmssWriteFile($dir.'/curl.cfg', 'user = "fixtur01:'.$password.'"'."\n");
        $this->assertTrue(chmod($dir.'/htpasswd', 0600));
        $this->assertTrue(chmod($dir.'/curl.cfg', 0600));

        // Use the actual managed block so the former inert suffix rule fails this test.
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.lighttpd');
        $this->assertSame(1, preg_match('/^# PMSS_WEBDAV_BEGIN$(.*?)^# PMSS_WEBDAV_END$/ms', $template, $match));
        $policy = str_replace(['##username', '/home/fixtur01/', '##PMSS_WEBDAV_WWW_POLICY##'],
            ['fixtur01', $dir.'/home/', ''], $match[1]);
        $port = $this->freePort();
        $config = 'server.modules = ( "mod_access", "mod_auth", "mod_authn_file", "mod_alias", "mod_webdav" )'."\n"
            .'server.bind = "127.0.0.1"'."\n".'server.port = '.$port."\n"
            .'server.document-root = "'.$dir.'/home/"'."\n".'server.errorlog = "'.$dir.'/error.log"'."\n"
            .'auth.backend = "htpasswd"'."\n".'auth.backend.htpasswd.userfile = "'.$dir.'/htpasswd"'."\n"
            .'auth.require = ( "/webdav-fixtur01/" => ( "method" => "basic", "realm" => "fixture", "require" => "user=fixtur01" ) )'."\n"
            .'alias.url = ( "/webdav-fixtur01/" => "'.$dir.'/home/" )'."\n".$policy;
        $this->pmssWriteFile($dir.'/lighttpd.conf', $config);
        $process = proc_open('exec '.escapeshellarg($servers[0]).' -D -m '.escapeshellarg(dirname($modules[0]))
            .' -f '.escapeshellarg($dir.'/lighttpd.conf'),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $dir.'/stdout.log', 'w'], 2 => ['file', $dir.'/stderr.log', 'w']], $pipes);
        $this->assertTrue(is_resource($process), 'Could not start the fixture lighttpd');
        try {
            $this->waitForPort($port, $dir);
            $base = 'http://127.0.0.1:'.$port.'/webdav-fixtur01/';
            $curl = escapeshellarg($clients[0]).' -q -K '.escapeshellarg($dir.'/curl.cfg');
            // Positive controls prove authentication and WebDAV mutations actually work.
            $this->assertSame('401', $this->status(escapeshellarg($clients[0]).' -q', 'GET', $base.'data/control.txt'));
            foreach ([['GET', 'data/control.txt', '200'], ['PROPFIND', 'data/control.txt', '207'],
                ['PUT', 'data/new.txt', '201'], ['GET', 'data/new.txt', '200'], ['DELETE', 'data/new.txt', '204'],
                ['COPY', 'data/control.txt', '201', 'data/copy.txt'], ['MOVE', 'data/copy.txt', '201', 'data/moved.txt'],
                ['GET', 'data/moved.txt', '200']] as $case) {
                $destination = isset($case[3]) ? $base.$case[3] : '';
                $this->assertSame($case[2], $this->status($curl, $case[0], $base.$case[1], $destination), implode(' ', $case));
            }
            $paths = array_merge(array_slice($files, 0, 4), ['.lighttpd/', '%2eprobe', '%2Elighttpd/state', '.new']);
            foreach ($paths as $path) {
                foreach (['GET', 'PUT', 'DELETE', 'PROPFIND', 'COPY', 'MOVE'] as $method) {
                    $this->assertSame('403', $this->status($curl, $method, $base.$path, $base.'data/target.txt'), $method.' '.$path);
                }
                foreach (['COPY', 'MOVE'] as $method) {
                    $this->assertSame('403', $this->status($curl, $method, $base.'data/control.txt', $base.$path), $method.' Destination '.$path);
                }
            }
            foreach ($files as $file) {
                $this->assertSame("fixture\n", file_get_contents($dir.'/home/'.$file), 'Preserved '.$file);
            }
            $this->assertFalse(file_exists($dir.'/home/.new'), 'Denied PUT must not create a dotfile');
            $this->assertFalse(file_exists($dir.'/home/data/target.txt'), 'Denied COPY/MOVE must not create a target');
        } finally {
            // Terminate only the child acquired above, before TestCase removes its files.
            proc_terminate($process);
            proc_close($process);
        }
    }

    /** Let the kernel pick a loopback port, following AgentWebLoginTest's fixture pattern. */
    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertTrue(is_resource($socket), 'No loopback port: '.$error);
        try {
            return (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        } finally {
            fclose($socket);
        }
    }

    /** Bound startup waiting and surface the fixture logs if parsing or binding fails. */
    private function waitForPort(int $port, string $dir): void
    {
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if (is_resource($socket)) {
                fclose($socket);
                return;
            }
            usleep(100000);
        }
        $this->fail('Fixture lighttpd did not listen: '.substr((string) @file_get_contents($dir.'/stderr.log'), -800)
            .substr((string) @file_get_contents($dir.'/error.log'), -800));
    }

    /** Send bounded requests without proxy inheritance, redirects or client path normalization. */
    private function status(string $curl, string $method, string $url, string $destination = ''): string
    {
        $command = $curl.' --noproxy "*" --path-as-is --max-time 3 -sS -o /dev/null -w "%{http_code}"'
            .' -X '.escapeshellarg($method).' -H "Depth: 0"';
        if ($method === 'PUT') {
            $command .= ' --data-binary "replacement"';
        }
        if ($destination !== '') {
            $command .= ' -H '.escapeshellarg('Destination: '.$destination);
        }
        return trim((string) shell_exec($command.' '.escapeshellarg($url).' 2>/dev/null'));
    }
}
