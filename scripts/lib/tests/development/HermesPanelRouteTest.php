<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/bin/pmssHermesRuntime.php';
require_once dirname(__DIR__, 2).'/lighttpd/pikkumiesUi.php';
require_once dirname(__DIR__, 2).'/nginxConfig/templates.php';
require_once dirname(__DIR__, 2).'/nginxConfig/pikkumiesUi.php';

/** Panel prefix and authentication contracts; fixtures run only on the testing VM. */
final class HermesPanelRouteTest extends TestCase
{
    public function testOnlyAnAccountOnTheExistingServerOriginCanBeConfigured(): void
    {
        $this->assertSame('https://node.example.com/user-qafix01/hermes/', \pmssHermesPanelUrl('qafix01', 'node.example.com'));
        foreach ([['../qafix01', 'node.example.com'], ['qafix01', ''], ['qafix01', 'localhost'], ['qafix01', '127.0.0.1'],
            ['qafix01', 'a@node.example.com'], ['qafix01', 'node.example.com:846'], ['qafix01', "node.example.com\r\n"],
            ['qafix01', 'node..example.com'], ['qafix01', '-node.example.com']] as $case) {
            $this->assertThrowsRuntime(static function () use ($case): void {
                \pmssHermesPanelUrl($case[0], $case[1]);
            }, 'valid panel hostname');
        }
    }

    public function testRelayLeavesThePanelPrefixToLighttpdSoItIsNeverDoubled(): void
    {
        require_once dirname(__DIR__, 4).'/etc/skel/bin/pmssHermesRelay.php';
        $p = '/user-qafix01/hermes';
        $head = "HTTP/1.1 302 Found\r\nLocation: /user-qafix01/hermes/login?next=%2F\r\n"
            ."Set-Cookie: __Secure-hermes_session_at=abc; Path=/user-qafix01/hermes; HttpOnly; Secure; SameSite=Strict\r\n"
            ."Set-Cookie: other=1; Path=/user-qafix01/hermes/api\r\nContent-Type: text/html";
        $this->assertSame("HTTP/1.1 302 Found\r\nLocation: /login?next=%2F\r\n"
            ."Set-Cookie: __Secure-hermes_session_at=abc; Path=/; HttpOnly; Secure; SameSite=Strict\r\n"
            ."Set-Cookie: other=1; Path=/api\r\nContent-Type: text/html", \pmssHermesRelayUnprefixHead($head, $p));
        $this->assertSame("Location: /\r\n", \pmssHermesRelayUnprefixHead("Location: https://node.example.com/user-qafix01/hermes\r\n", $p));
        // Another account's mount, a longer name sharing the prefix, a foreign site and the Pikkumies relay stay as they are.
        foreach (["Location: /user-qafix02/hermes/x", "Location: /user-qafix01/hermesx/", "Location: https://evil.example/",
            "Set-Cookie: a=1; Path=/user-qafix01/hermes2", "X-Note: Path=/user-qafix01/hermes"] as $keep) {
            $this->assertSame($keep, \pmssHermesRelayUnprefixHead($keep, $p));
        }
        $this->assertSame($head, \pmssHermesRelayUnprefixHead($head, ''));
    }

    public function testLighttpdKeepsThePanelZoneAndStripsOnlyTheHermesPrefix(): void
    {
        $route = \pmssLighttpdHermesUiBlock('qafix01', 24000);
        $this->assertStringContainsAllStrings(['^/user-qafix01/hermes/', '"Authorization" => ""',
            '"X-Forwarded-Prefix" => "/user-qafix01/hermes"', '"/user-qafix01/hermes/" => "/"',
            '/home/qafix01/.hermes/pmss-dashboard.sock', '"upgrade" => "enable"'], $route);
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.lighttpd');
        $this->assertTrue(strpos($template, '"mod_auth",') < strpos($template, '"mod_setenv",'));
        $this->assertStringContainsString('"/user-##username/" =>', $template);
        foreach ([['../qafix01', 24000], ['qafix01', 443], ['qafix01', 65536]] as $case)
            $this->assertSame('', \pmssLighttpdHermesUiBlock($case[0], $case[1]));
        $render = $this->pmssReadRepoFile('scripts/lib/lighttpd/configRender.php');
        $this->assertStringNotContainsString('pmss-install.status', $render, 'the route must exist before install');
    }

    public function testNginxCorrectsLighttpdResponseMappingWithoutRewritingUpstreamCode(): void
    {
        $template = str_replace(['##username', '##serverPort', '##PMSS_HERMES_RELAY_HEADERS##'],
            ['qafix01', '12345', \pmssPikkumiesUiRelayHeaders()],
            $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-user'));
        $this->assertStringContainsAllStrings(['location ^~ /user-qafix01/hermes/',
            'proxy_pass http://127.0.0.1:12345/user-qafix01/hermes/;',
            'proxy_set_header X-Forwarded-For $remote_addr;', 'proxy_set_header X-Forwarded-Proto https;',
            'proxy_set_header Authorization $http_authorization;', 'access_log off;',
            'if ($http_origin = "https://$host")', '(?s)^GET:/user-qafix01/hermes/api/.*:(cross-site|same-site)$',
            'if ($pmss_hermes_request = "GET::")', 'limit_conn addr 32;',
            'if ($uri = /user-qafix01/hermes/api/cron/fire) { return 404; }'], $template);
        preg_match('/proxy_redirect ~([^ ]+) ([^;]+);/', $template, $redirect);
        preg_match('/proxy_cookie_path ~([^ ]+) ([^;]+);/', $template, $cookie);
        // lighttpd 1.4.69 mod_proxy.c reverses its mapping on already-prefixed Location and Path values.
        foreach (['/', '/login?next=%2Fuser-qafix01%2Fhermes%2F', '/sessions/1', '/assets/main.js', ''] as $suffix) {
            $double = '/user-qafix01/hermes/user-qafix01/hermes'.$suffix;
            $this->assertSame('/user-qafix01/hermes'.$suffix, preg_replace('#'.$redirect[1].'#', $redirect[2], $double));
            $this->assertSame('/user-qafix01/hermes'.$suffix, preg_replace('#'.$redirect[1].'#', $redirect[2], 'https://node.example.com'.$double));
            $this->assertSame('/user-qafix01/hermes'.$suffix, preg_replace('#'.$cookie[1].'#', $cookie[2], $double));
        }
    }

    public function testPublicRoutesCannotReplaceThePanelBasicRealmAndPortCIsRetired(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-user');
        foreach (['public-', '~'] as $prefix) {
            $this->assertSame(1, preg_match('~location /'.preg_quote($prefix, '~').'##username/ \{\s*(?:#[^\n]*\n\s*)?proxy_hide_header WWW-Authenticate;~', $template));
        }
        // The legacy Deluge path also reaches lighttpd outside auth.require.
        $this->assertSame(1, preg_match('~location /deluge-##username/ \{[^}]*proxy_hide_header WWW-Authenticate;~', $template));
        $public = \pmssNginxUserSubdomainTemplates()['public'];
        $this->assertSame(2, substr_count($public, 'proxy_hide_header WWW-Authenticate;'));
        foreach (['scripts/lib/nginxConfig/setup.php', 'scripts/lib/nginxConfig/userConfigsGenerate.php'] as $path) {
            $source = $this->pmssReadRepoFile($path);
            $this->assertStringNotContainsString('pmssHermesUiNginxBlock(', $source);
            $this->assertStringNotContainsString('pmssHermesUiDefaultNginxBlock(', $source);
        }
    }

    public function testHandoffUsesOriginBasedUrlsAndOnlyASingleUseFragment(): void
    {
        $page = $this->pmssReadRepoFile('etc/skel/www/hermesLogin.php');
        $this->assertStringContainsAllStrings(['new URL(base, location.origin)', "new URL(base + 'login', location.origin)",
            "new URL(base + '__pmss-session', location.origin)", "history.replaceState(null, '', location.pathname)",
            '/^#token=([0-9a-f]{64})$/', "credentials:'same-origin'", 'Referrer-Policy: no-referrer'], $page);
        $this->assertStringNotContainsString('location.href)', $page);
        $this->assertStringNotContainsString('?token=', $page);
        $this->assertStringContainsString("'www/hermesLogin.php'", $this->pmssReadRepoFile('scripts/lib/update/users/filesystem.php'));
    }

    public function testSessionCookieMustMatchTheNativePanelPrefixAndSecurityAttributes(): void
    {
        $prefix = '/user-qafix01/hermes';
        $cookie = '__Secure-hermes_session_at=token; Path='.$prefix.'; Secure; HttpOnly; SameSite=lax';
        $response = "HTTP/1.1 200 OK\r\nSet-Cookie: ".$cookie."\r\n\r\n";
        $this->assertSame([$cookie], \pmssHermesRelayLoginCookies($response, $prefix));
        foreach ([str_replace('200 OK', '401 No', $response), str_replace('__Secure-', '__Host-', $response),
            str_replace('Path='.$prefix, 'Path=/', $response), str_replace('; Secure', '', $response),
            str_replace('; HttpOnly', '', $response)] as $bad)
            $this->assertSame(null, \pmssHermesRelayLoginCookies($bad, $prefix));
    }
}
