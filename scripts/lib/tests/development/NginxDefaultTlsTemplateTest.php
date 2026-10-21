<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class NginxDefaultTlsTemplateTest extends TestCase
{
    private function template(): string
    {
        return $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-site-default-ssl');
    }

    public function testDefaultSiteInheritsProtocolsAndKeepsOnlyExistingAeadCiphers(): void
    {
        $template = $this->template();
        foreach (['SSLv3', 'TLSv1.1', 'DES-CBC3', 'ssl_protocols'] as $removed) {
            $this->assertFalse(strpos($template, $removed) !== false);
        }
        $global = $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-conf');
        $this->assertTrue(strpos($global, 'ssl_protocols TLSv1.2 TLSv1.3;') !== false);
        $this->assertSame(1, preg_match('/ssl_ciphers\s+"([^"]+)";/', $template, $matches));
        $ciphers = explode(':', $matches[1]);
        $this->assertSame([
            'ECDHE-ECDSA-CHACHA20-POLY1305', 'ECDHE-RSA-CHACHA20-POLY1305',
            'ECDHE-ECDSA-AES128-GCM-SHA256', 'ECDHE-RSA-AES128-GCM-SHA256',
            'ECDHE-ECDSA-AES256-GCM-SHA384', 'ECDHE-RSA-AES256-GCM-SHA384',
            'DHE-RSA-AES128-GCM-SHA256', 'DHE-RSA-AES256-GCM-SHA384',
        ], $ciphers);
        foreach ($ciphers as $cipher) {
            $this->assertMatches('/(GCM|POLY1305)/', $cipher);
        }
    }

    public function testRenderedFixturePassesNginxSyntaxWhenAvailable(): void
    {
        $nginx = trim((string) shell_exec('command -v nginx'));
        if ($nginx === '') {
            throw new SkipTest('nginx binary unavailable');
        }
        $openssl = trim((string) shell_exec('command -v openssl'));
        if ($openssl === '') {
            throw new SkipTest('openssl binary unavailable for temporary certificate');
        }
        $dir = $this->pmssMakeTempDir('pmss-nginx-tls-', 0700);
        $cert = $dir.'/cert.pem';
        $key = $dir.'/key.pem';
        $makeCert = escapeshellarg($openssl).' req -x509 -nodes -newkey rsa:2048 -days 1'
            .' -subj '.escapeshellarg('/CN=localhost').' -keyout '.escapeshellarg($key)
            .' -out '.escapeshellarg($cert).' >/dev/null 2>&1';
        exec($makeCert, $output, $status);
        $this->assertSame(0, $status);

        $site = str_replace(
            ['/etc/nginx/ssl/nginx.crt', '/etc/nginx/ssl/nginx.key'],
            [$cert, $key],
            $this->template()
        );
        // Unprivileged syntax check: no system log, temp or low-port access from a CI runner.
        $temps = '';
        foreach (['client_body', 'proxy', 'fastcgi', 'uwsgi', 'scgi'] as $temp) $temps .= "{$temp}_temp_path {$dir}/{$temp};\n";
        $config = "pid {$dir}/nginx.pid;\nerror_log stderr;\nevents {}\nhttp {\naccess_log off;\n".$temps
            ."ssl_protocols TLSv1.2 TLSv1.3;\nserver { listen 127.0.0.1:".random_int(40000, 49999)." ssl;\n"
            .$site."\n}\n}\n";
        $path = $dir.'/nginx.conf';
        file_put_contents($path, $config);
        $command = escapeshellarg($nginx).' -t -p '.escapeshellarg($dir.'/')
            .' -c '.escapeshellarg($path).' 2>&1';
        $output = [];
        exec($command, $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
    }
}
