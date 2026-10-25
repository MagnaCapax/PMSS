<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/** Per-user nginx locations: proxied responses drop Service-Worker-Allowed, and WebDAV answers only clients, tabs and media. */
class nginxUserLocationHeadersTest extends TestCase
{
    /** The body of each location block in the per-user template, keyed by its location line. */
    private function locations(): array
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-user');
        preg_match_all('/^(location [^{\n]+)\{(.*?)^\}/ms', $template, $m, PREG_SET_ORDER);
        $blocks = [];
        foreach ($m as $block) {
            $blocks[trim($block[1])] = $block[2];
        }
        return $blocks;
    }

    public function testEveryProxiedLocationDropsServiceWorkerAllowed(): void
    {
        $proxied = 0;
        foreach ($this->locations() as $name => $body) {
            if (strpos($body, 'proxy_pass ') === false) {
                continue;
            }
            $proxied++;
            $this->assertStringContainsString('proxy_hide_header Service-Worker-Allowed;', $body, "$name must drop Service-Worker-Allowed");
        }
        $this->assertSame(5, $proxied, 'user, public, ~, webdav and deluge locations proxy to the account web server');
    }

    public function testWebdavRefusesScriptAndFrameRequestsBeforeProxying(): void
    {
        $body = $this->locations()['location /webdav-##username/'] ?? '';
        $this->assertOrderedStrings([
            'if ($http_sec_fetch_dest !~ ^(|document|image|audio|video|track)$) {',
            'return 403;',
            'proxy_pass http://127.0.0.1:##serverPort/webdav-##username/;',
        ], $body, '', 'the WebDAV destination check must run before the proxy: ');
    }

    public function testWebdavDestinationRuleAllowsClientsTabsAndMediaOnly(): void
    {
        $rule = '/^(|document|image|audio|video|track)$/';
        foreach (['', 'document', 'image', 'audio', 'video', 'track'] as $dest) {
            $this->assertSame(1, preg_match($rule, $dest), "Sec-Fetch-Dest '$dest' must pass");
        }
        foreach (['empty', 'iframe', 'frame', 'embed', 'object', 'script', 'worker', 'serviceworker', 'style'] as $dest) {
            $this->assertSame(0, preg_match($rule, $dest), "Sec-Fetch-Dest '$dest' must be refused");
        }
    }
}
