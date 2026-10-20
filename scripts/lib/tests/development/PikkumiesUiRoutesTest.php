<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/nginxUserHosts.php';
require_once dirname(__DIR__, 2).'/nginxConfig/pikkumiesUi.php';
require_once dirname(__DIR__, 2).'/lighttpd/pikkumiesUi.php';

final class PikkumiesUiRoutesTest extends TestCase
{
    /** Evaluate the rendered nginx map's ordered regex rows. */
    private function mapResult(string $map, string $input): int
    {
        preg_match('/default ([01]);/', $map, $default);
        $value = (int) ($default[1] ?? -1);
        preg_match_all('/^\s*~([^\s]+) ([01]);$/m', $map, $rows, PREG_SET_ORDER);
        foreach ($rows as $row) {
            if (preg_match('#'.str_replace('#', '\\#', $row[1]).'#', $input) === 1) return (int) $row[2];
        }
        return $value;
    }

    public function testRenderedAliasPrefixesDenyEveryInternalUiMount(): void
    {
        $template = str_replace(['##username', '##serverPort'], ['uitester', '12345'],
            $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-user'));
        preg_match_all('/location (\^~ )?(\/[^ ]+) \{([^}]*)\}/s', $template, $matches, PREG_SET_ORDER);
        foreach (['/public-uitester/', '/~uitester/'] as $prefix) {
            foreach (['a/', 'b/', 'c/', 'c-agentboard/'] as $ui) {
                $request = $prefix.'__pmss-ui-'.$ui;
                $chosen = null;
                foreach ($matches as $location) {
                    if (strpos($request, $location[2]) !== 0) continue;
                    if ($chosen === null || strlen($location[2]) > strlen($chosen[2])) $chosen = $location;
                }
                $this->assertTrue($chosen !== null, $request);
                $this->assertStringContainsString('return 404;', $chosen[3]);
                $this->assertFalse(strpos($chosen[3], 'proxy_pass') !== false);
            }
        }
    }

    public function testPortAMapsRejectForeignUpgradeAndCrossSiteApi(): void
    {
        $block = \pmssPikkumiesUiNginxBlock('uitester', 'node.example.com', '0123456789abcdef.mcx.fi', 12345);
        preg_match_all('/map "[^"]+" \$[^\s]+ \{(.*?)\n\}/s', $block, $maps);
        $this->assertSame(2, count($maps[1]));
        $this->assertSame(0, $this->mapResult($maps[1][0], 'GET:websocket:https://evil.example'));
        $this->assertSame(1, $this->mapResult($maps[1][0], 'GET:websocket:https://0123456789abcdef.mcx.fi:844'));
        $this->assertSame(0, $this->mapResult($maps[1][1], 'GET:/api/sessions:cross-site'));
        $this->assertSame(1, $this->mapResult($maps[1][1], 'GET:/api/sessions:same-origin'));
    }
    public function testPortCollisionRefusesForeignListener(): void
    {
        $foreign = 'LISTEN 0 128 0.0.0.0:844 0.0.0.0:* users:(("other",pid=7,fd=3))';
        $nginx = 'LISTEN 0 128 0.0.0.0:844 0.0.0.0:* users:(("nginx",pid=7,fd=3))';
        $this->assertFalse(\pmssPikkumiesUiPortAvailable(844, $foreign));
        $this->assertTrue(\pmssPikkumiesUiPortAvailable(844, $nginx));
        $this->assertTrue(\pmssPikkumiesUiPortAvailable(844, str_replace(':844', ':845', $foreign)));
        $this->assertTrue(\pmssPikkumiesUiPortAvailable(844, ''));
    }

    public function testBillingPublicationUsesPrivateAccountReadAndMarkerGate(): void
    {
        $root = $this->pmssMakeTempDir('pmss-ui-billing-');
        $directory = $root.'/billing';
        $marker = $root.'/enabled';
        $write = [];
        $writer = static function ($path, $content, $owner, $group, $mode) use (&$write): bool {
            $write = [$path, $content, $owner, $group, $mode];
            return true;
        };
        $this->assertTrue(\pmssPikkumiesUiIdentityWrite($directory.'/uitester', '123', 'uitester', $writer));
        $this->assertSame([$directory.'/uitester', "123\n", 'root', 'uitester', 0640], $write);
        $this->assertTrue(\pmssPikkumiesUiIdentityPublish('uitester', '123', $directory, $marker));
        $this->assertFalse(is_dir($directory));
        file_put_contents($marker, '');
        $this->assertFalse(\pmssPikkumiesUiIdentityPublish('uitester', '123', $directory, $marker));
        $this->assertTrue(is_dir($directory));
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

    public function testPortCUsesPrivateServiceOriginAndSeparateBackendPaths(): void
    {
        $live = $this->pmssMakeTempDir('pmss-hermes-ui-cert-', 0700);
        mkdir($live.'/uitester.node.example.com');
        file_put_contents($live.'/uitester.node.example.com/fullchain.pem', 'fixture');
        file_put_contents($live.'/uitester.node.example.com/privkey.pem', 'fixture');
        $block = \pmssHermesUiNginxBlock('uitester', 'node.example.com',
            '0123456789abcdef.mcx.fi', 12345, $live);
        $this->assertStringContainsAllStrings([
            'listen 846 ssl;', 'server_name 0123456789abcdef.mcx.fi;',
            'location = /api/cron/fire { return 404; }', 'same-origin|none',
            'auth_request /__pmss-auth;', 'proxy_set_header Authorization "";',
            'agentboard $http_authorization;',
            'proxy_set_header X-Forwarded-Proto https;', 'pmss_ui_noargs',
            '/__pmss-select-web', '/__pmss-select-agentboard',
            '__Host-pmss_ui_c=web', 'proxy_cookie_domain ~.+ "";',
        ], $block);
        $this->assertFalse(strpos($block, 'X-Forwarded-Prefix') !== false);
        $this->assertFalse(strpos($block, '$args') !== false);
        $this->assertStringContainsString('listen 846 ssl default_server;',
            \pmssHermesUiDefaultNginxBlock("    ssl_certificate /safe/fullchain.pem;\n"));
        $hermes = \pmssLighttpdHermesUiBlock('uitester', 24000);
        $agentboard = \pmssLighttpdHermesUiBlock('uitester', 24000, 'vanilla-agentboard');
        $this->assertStringContainsAllStrings(['^/__pmss-ui-c/', 'X-Forwarded-Proto', '"Authorization" => ""'], $hermes);
        $this->assertStringContainsString('/home/uitester/.hermes/pmss-dashboard.sock', $hermes);
        $this->assertStringContainsString('/home/uitester/.config/pmss/ui/agentboard.sock', $agentboard);
        $this->assertFalse(strpos($agentboard, '"Authorization" => ""') !== false);
        $this->assertSame('', \pmssLighttpdHermesUiBlock('../uitester', 24000));
    }

    public function testEncodedAndRepeatedPathSegmentsReturnBadRequest(): void
    {
        foreach ([\pmssPikkumiesHermesNginxBlock('uitester', 'node.example.com', '0123456789abcdef.mcx.fi', 12345),
            \pmssHermesUiNginxBlock('uitester', 'node.example.com', '0123456789abcdef.mcx.fi', 12345)] as $block) {
            $this->assertTrue(preg_match('/if \(\$request_uri ~\* "([^"]+)"\) \{ return 400; \}/', $block, $match) === 1);
            foreach (['/%2e/', '/%2F/', '/a/../b', '//api'] as $uri)
                $this->assertTrue(preg_match('~'.$match[1].'~i', $uri) === 1, $uri);
            foreach (['/', '/api/auth/me', '/assets/main.js'] as $uri)
                $this->assertFalse(preg_match('~'.$match[1].'~i', $uri) === 1, $uri);
        }
    }

    public function testPortCChoiceReadsOnlyTheHostPrefixedCookie(): void
    {
        $block = \pmssHermesUiNginxBlock('uitester', 'node.example.com', '0123456789abcdef.mcx.fi', 12345);
        $this->assertTrue(preg_match('/map \$http_cookie \$[a-z0-9_]+ \{\s*default "";\s*~([^ ]+) \$1;/s',
            $block, $match) === 1);
        foreach (['__Host-pmss_ui_c=web' => 'web', 'other=x; __Host-pmss_ui_c=agentboard; x=y' => 'agentboard'] as $cookie => $expected) {
            $this->assertTrue(preg_match('~'.$match[1].'~', $cookie, $value) === 1);
            $this->assertSame($expected, $value[1]);
        }
        foreach (['pmss_ui_c=agentboard', '__Host-pmss_ui_c=other', 'x__Host-pmss_ui_c=web'] as $cookie)
            $this->assertFalse(preg_match('~'.$match[1].'~', $cookie) === 1);
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
