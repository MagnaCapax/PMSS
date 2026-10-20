<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/nginxUserHosts.php';
require_once dirname(__DIR__, 2).'/nginxConfig/pikkumiesUi.php';
require_once dirname(__DIR__, 2).'/lighttpd/pikkumiesUi.php';

final class PikkumiesUiRoutesTest extends TestCase
{
    public function testPortCollisionRefusesForeignListener(): void
    {
        $foreign = 'LISTEN 0 128 0.0.0.0:844 0.0.0.0:* users:(("other",pid=7,fd=3))';
        $nginx = 'LISTEN 0 128 0.0.0.0:844 0.0.0.0:* users:(("nginx",pid=7,fd=3))';
        $this->assertFalse(\pmssPikkumiesUiPortAvailable(844, $foreign));
        $this->assertTrue(\pmssPikkumiesUiPortAvailable(844, $nginx));
        $this->assertTrue(\pmssPikkumiesUiPortAvailable(844, str_replace(':844', ':845', $foreign)));
        $this->assertTrue(\pmssPikkumiesUiPortAvailable(844, ''));
    }

    public function testPortABlockUsesOnlyServiceHostAndPrivateInternalMount(): void
    {
        $host = '0123456789abcdef.mcx.fi';
        $live = $this->pmssMakeTempDir('pmss-ui-cert-', 0700);
        mkdir($live.'/uitester.node.example.com');
        file_put_contents($live.'/uitester.node.example.com/fullchain.pem', 'fixture');
        file_put_contents($live.'/uitester.node.example.com/privkey.pem', 'fixture');
        $this->assertTrue(\pmssPikkumiesUiCertificateReady('uitester', 'node.example.com', $live));
        $this->assertFalse(\pmssPikkumiesUiCertificateReady('bob', 'node.example.com', $live));
        $block = \pmssPikkumiesUiNginxBlock('uitester', 'node.example.com', $host, 12345, $live);
        $this->assertStringContainsAllStrings([
            'listen 844 ssl;', 'server_name '.$host.';', 'location = /bar',
            'alias /var/www/pikkumies/bar.html;', 'location / {',
            'proxy_pass http://127.0.0.1:12345/__pmss-ui-a/;',
            'frame-ancestors https://node.example.com', 'limit_conn addr 32',
            'proxy_read_timeout 3600s', 'default 0;', 'same-origin|none',
        ], $block);
        $this->assertFalse(strpos($block, 'server_name uitester') !== false);
        $this->assertStringContainsString($live.'/uitester.node.example.com/fullchain.pem', $block);
        $this->assertFalse(strpos($block, $live.'/'.$host.'/') !== false);
        $this->assertFalse(strpos($block, 'alias /home/') !== false);
        $this->assertSame(1, substr_count($block, 'location / {'));
        $this->assertSame(1, substr_count($block, 'location = /bar'));
    }

    public function testDefaultServerAndAccountProxyAreSeparate(): void
    {
        $default = \pmssPikkumiesUiDefaultNginxBlock("    ssl_certificate /safe/fullchain.pem;\n");
        $this->assertStringContainsAllStrings(['listen 844 ssl default_server;', 'return 444;'], $default);
        $this->assertFalse(strpos($default, 'proxy_pass') !== false);
        $lighttpd = \pmssLighttpdPikkumiesUiBlock('uitester');
        $this->assertStringContainsAllStrings([
            '^/__pmss-ui-a/', 'map-urlpath', '"/__pmss-ui-a/" => "/"',
            '/home/uitester/.mcxPikkumies/pikkumies/local/ui/ui.sock',
        ], $lighttpd);
        $this->assertSame('', \pmssLighttpdPikkumiesUiBlock('../uitester'));
    }

    public function testRootOwnedBarPageKeepsTheAskQueueAndCopyBounded(): void
    {
        $bar = $this->pmssReadRepoFile('etc/seedbox/config/pikkumies-bar.html');
        $offline = $this->pmssReadRepoFile('etc/seedbox/config/pikkumies-not-running.html');
        $setup = $this->pmssReadRepoFile('scripts/lib/nginxConfig/setup.php');
        $this->assertTrue(strlen($bar) < 10240);
        $this->assertStringContainsAllStrings([
            'lang="en"', 'maxlength="4000"', 'aria-label="Ask Pikkumies"',
            'How can i help you today? -Pikkumies', 'e.isComposing',
            "'pending-ask'", "'pikkumies:draft'", 'queue.length>=5',
            'now-item.ts<3600000', 'crypto.getRandomValues', 'pikkumies:rows:',
            'prefers-reduced-motion', 'pointer:coarse', 'window.top===window',
            'window.parent.postMessage(name,target)',
        ], $bar);
        $this->assertStringContainsString('pikkumies:reconnect', $offline);
        $this->assertStringContainsString('pmssPikkumiesUiPagesInstall()', $setup);
        $this->assertFalse(strpos($bar.$offline, 'Try again</button>') !== false);
    }
}
