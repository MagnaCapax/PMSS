<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/nginxConfig/userConfigsGenerate.php';
require_once dirname(__DIR__, 2).'/lighttpd/pikkumiesUi.php';

/** Panel routes have distinct prefixes and retain the existing account authentication. */
final class PikkumiesUiRoutesTest extends TestCase
{
    public function testBothLighttpdMountsStripAuthorizationWithoutReplacingPanelAuth(): void
    {
        foreach (['pikkumies' => \pmssLighttpdPikkumiesUiBlock('uitester'),
            'pikkumies-hermes' => \pmssLighttpdPikkumiesHermesBlock('uitester')] as $route => $block) {
            $prefix = '/user-uitester/'.$route.'/';
            $this->assertStringContainsAllStrings(['^'.$prefix, '"Authorization" => ""',
                'setenv.set-request-header = ( "Authorization" => "" )',
                '"map-urlpath" => ( "'.$prefix.'" => "/" )', '"upgrade" => "enable"',
                'server.max-read-idle = 3600', 'server.max-write-idle = 3600'], $block);
            $this->assertStringNotContainsString('auth.require', $block);
            $this->assertSame(1, substr_count($block, 'setenv.set-request-header ='));
            if ($route === 'pikkumies-hermes') {
                $this->assertStringContainsAllStrings(['setenv.set-request-header += ( "X-Forwarded-Prefix" => "/user-uitester/pikkumies-hermes" )',
                    '/home/uitester/.mcxPikkumies/pikkumies/local/ui/hermes/ui.sock'], $block);
            } else {
                $this->assertStringNotContainsString('X-Forwarded-Prefix', $block);
                $this->assertStringContainsString('/home/uitester/.mcxPikkumies/pikkumies/local/ui/ui.sock', $block);
            }
        }
        foreach (['', '../test', 'UPPER', 'toolongname', 'test/'] as $user) {
            $this->assertSame('', \pmssLighttpdPikkumiesUiBlock($user));
            $this->assertSame('', \pmssLighttpdPikkumiesHermesBlock($user));
        }
    }

    public function testMountsDoNotOverlapOtherAccountRoutes(): void
    {
        foreach (['pikkumies', 'pikkumies-hermes'] as $route) {
            $regex = '~^/user-uitester/'.$route.'/~';
            $this->assertSame(1, preg_match($regex, '/user-uitester/'.$route.'/api/status'));
            foreach (['hermes', 'pikkumiesOpen.php', 'pikkumiesPanel.php', 'rutorrent', 'configure.php',
                $route === 'pikkumies' ? 'pikkumies-hermes' : 'pikkumies'] as $other) {
                $this->assertSame(0, preg_match($regex, '/user-uitester/'.$other.'/'));
            }
            $this->assertSame(0, preg_match($regex, '/user-other/'.$route.'/'));
        }
    }

    public function testNginxLocationsKeepPanelHeadersAndExactOriginRules(): void
    {
        $template = strtr($this->pmssReadRepoFile('etc/seedbox/config/template.nginx-user'), [
            '##username' => 'uitester', '##serverPort' => '12345',
            '##PMSS_HERMES_RELAY_HEADERS##' => \pmssPikkumiesUiRelayHeaders(),
        ]);
        foreach (['pikkumies', 'pikkumies-hermes'] as $route) {
            $prefix = '/user-uitester/'.$route;
            $this->assertStringContainsAllStrings(['location = '.$prefix.' {', 'return 308 '.$prefix.'/;'], $template);
            $start = strpos($template, 'location ^~ '.$prefix.'/ {');
            $this->assertTrue($start !== false);
            $block = substr($template, $start, strpos($template, "\n}\n", $start) - $start);
            $this->assertStringContainsAllStrings(['proxy_pass http://127.0.0.1:12345'.$prefix.'/;',
                'proxy_set_header Authorization $http_authorization;', 'proxy_set_header X-Forwarded-Proto https;',
                'proxy_set_header X-PMSS-Proxy-Hop 127.0.0.1;', 'proxy_bind 127.0.0.2;',
                'proxy_set_header X-Forwarded-For $remote_addr;', 'proxy_set_header Host $host;',
                'proxy_set_header Upgrade $http_upgrade;', 'proxy_set_header Connection "upgrade";',
                'proxy_hide_header Service-Worker-Allowed;', 'access_log off;',
                'if ($scheme != https) { return 403; }', 'if ($http_origin = "https://$host")',
                '(?s)^GET:'.$prefix.'/api/.*:(cross-site|same-site)$', '= "GET::")',
                'proxy_redirect ~^(?:https?://[^/]+)?('.$prefix.')'.$prefix.'(.*)$ $1$2;',
                'proxy_cookie_path ~^'.$prefix.$prefix.'(.*)$ '.$prefix.'$1;'], $block);
            $this->assertStringNotContainsString('auth.require', $block);
            $this->assertStringNotContainsString('X-Forwarded-Prefix', $block);
            $this->assertSame($route === 'pikkumies-hermes', strpos($block, '/api/cron/fire) { return 404; }') !== false);
        }
    }

    public function testRendererEmitsBothRoutesWithoutHostOrPortGates(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/lighttpd/configRender.php', [
            '$config .= pmssLighttpdPikkumiesUiBlock($user);', '$config .= pmssLighttpdPikkumiesHermesBlock($user);',
        ], ['pikkumiesEternals.enabled', 'media-stack-pikkumies-hermes-', '/opt/pikkumies-agentboard/agentboard']);
    }
}
