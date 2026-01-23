<?php
namespace PMSS\Tests\Production;

use PMSS\Tests\TestCase;
use PMSS\Tests\SkipTest;

require_once __DIR__.'/../common/TestCase.php';

class WebdavResponseTest extends TestCase
{
    private function findAnyUserPort(): array
    {
        $dir = '/etc/seedbox/runtime/ports';
        if (!is_dir($dir)) {
            return [];
        }
        $candidates = glob($dir.'/lighttpd-*');
        if (!is_array($candidates) || empty($candidates)) {
            return [];
        }

        sort($candidates, SORT_STRING);
        foreach ($candidates as $path) {
            $base = basename($path);
            if (strpos($base, 'lighttpd-') !== 0) {
                continue;
            }
            $user = substr($base, strlen('lighttpd-'));
            if (!is_string($user) || !preg_match('/^[a-z][a-z0-9]{0,7}$/', $user)) {
                continue;
            }
            $portRaw = trim((string)@file_get_contents($path));
            if ($portRaw === '' || !ctype_digit($portRaw)) {
                continue;
            }
            $port = (int)$portRaw;
            if ($port < 1024 || $port > 65535) {
                continue;
            }
            return ['user' => $user, 'port' => $port];
        }

        return [];
    }

    private function curlStatus(string $url, bool $insecureTls = false): string
    {
        $cmd = 'curl -sS -o /dev/null -w "%{http_code}" --max-time 5';
        if ($insecureTls) {
            $cmd .= ' -k';
        }
        $cmd .= ' '.escapeshellarg($url).' 2>/dev/null';
        $out = shell_exec($cmd);
        return trim((string)$out);
    }

    public function testLighttpdWebdavRespondsWithAuthChallenge(): void
    {
        $pair = $this->findAnyUserPort();
        if (empty($pair)) {
            throw new SkipTest('No lighttpd port assignments found under /etc/seedbox/runtime/ports');
        }

        $user = $pair['user'];
        $port = (int)$pair['port'];
        $url = sprintf('http://127.0.0.1:%d/webdav-%s/', $port, $user);
        $code = $this->curlStatus($url, false);

        // Expect a 401 challenge without credentials. If this fails to respond, the
        // user lighttpd instance may be down and might need a restart.
        $this->assertEquals('401', $code, 'Expected 401 from '.$url.', got '.$code);
    }

    public function testNginxHttpRedirectsWebdavToHttps(): void
    {
        $pair = $this->findAnyUserPort();
        if (empty($pair)) {
            throw new SkipTest('No lighttpd port assignments found under /etc/seedbox/runtime/ports');
        }

        $user = $pair['user'];
        $url = sprintf('http://127.0.0.1/webdav-%s/', $user);
        $code = $this->curlStatus($url, false);

        // WebDAV must not be usable over plain HTTP; redirect to HTTPS is acceptable.
        $this->assertTrue(in_array($code, ['301', '302'], true), 'Expected 301/302 from '.$url.', got '.$code);
    }
}

