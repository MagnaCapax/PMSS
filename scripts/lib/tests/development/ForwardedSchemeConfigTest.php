<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/nginxConfig/templates.php';
require_once dirname(__DIR__, 2).'/lighttpd/pikkumiesUi.php';

/** ADR 0153: proxied apps receive the scheme the visitor used; lighttpd keeps 127.0.0.1 as the client. */
final class ForwardedSchemeConfigTest extends TestCase
{
    private const PARAMS = ['etc/seedbox/config/template.nginx-proxy_params', 'etc/seedbox/config/template.nginx-webdav_proxy_params'];

    public function testLighttpdReadsTheSchemeOnlyFromTheNginxHop(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.lighttpd');
        $this->assertStringContainsString('"mod_extforward",', $template);
        $this->assertSame(1, preg_match_all('/^extforward\.forwarder \+= \( "127\.0\.0\.2" => "trust" \)$/m', $template));
        $this->assertSame(1, preg_match_all('/^extforward\.headers \+= \( "X-PMSS-Proxy-Hop" \)$/m', $template));
        $this->assertSame(2, preg_match_all('/^extforward\./m', $template), 'only the two settings above');
        // "+=" after the account's own includes merges with extforward lines an account already has.
        $this->assertOrderedStrings(['include "/home/##username/.lighttpd/custom.d/*.conf"', 'extforward.forwarder +='], $template);
    }

    public function testNginxSendsTheHopFromTheBindAddress(): void
    {
        foreach (self::PARAMS as $path) {
            $this->assertStringContainsAllStrings([
                'proxy_set_header X-Forwarded-Proto $scheme;',
                'proxy_set_header Forwarded "for=\"$remote_addr\";proto=$scheme";',
                'proxy_bind 127.0.0.2;',
                'proxy_set_header X-PMSS-Proxy-Hop 127.0.0.1;',
            ], $this->pmssReadRepoFile($path), $path.': ');
        }
    }

    /** The parameter files carry proxy_bind and per-request headers, which belong to a location. */
    public function testProxyParamsAreIncludedOnlyInsideLocationBlocks(): void
    {
        $sources = ['template.nginx-user' => $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-user')];
        foreach (\pmssNginxUserSubdomainTemplates() as $name => $block) {
            $sources['subdomain '.$name] = $block;
        }
        $includes = 0;
        foreach ($sources as $name => $text) {
            $stack = [];
            foreach (preg_split('/\r?\n/', $text) as $line) {
                if (preg_match('/^\s*include\s+\/etc\/nginx\/(webdav_)?proxy_params;/', $line) === 1) {
                    $includes++;
                    $this->assertSame(0, strpos((string) end($stack), 'location'), $name.': proxy params outside a location');
                }
                $code = preg_replace('/(^|\s)#.*$/', '', $line); // "##username" is a placeholder, not a comment
                for ($i = 0, $n = strlen($code); $i < $n; $i++) {
                    if ($code[$i] === '{') {
                        $stack[] = trim(substr($code, 0, $i));
                    } elseif ($code[$i] === '}') {
                        array_pop($stack);
                    }
                }
            }
        }
        $this->assertTrue($includes >= 6, 'expected the user and subdomain locations to include proxy params');
    }

    public function testAccountUiMountsSendTheHop(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/nginxConfig/pikkumiesUi.php');
        $scheme = preg_match_all('/^( +)proxy_set_header X-Forwarded-Proto https;\n\1proxy_set_header X-PMSS-Proxy-Hop 127\.0\.0\.1;\n\1proxy_bind 127\.0\.0\.2;$/m', $source);
        $this->assertSame(substr_count($source, 'proxy_set_header X-Forwarded-Proto https;'), $scheme);
        $this->assertTrue($scheme >= 8, 'every account UI location that sets the scheme also sends the hop');
        $this->assertSame($scheme, substr_count($source, 'proxy_set_header Forwarded "for=\\\\"\\$remote_addr\\\\";proto=https";'));
        foreach ([\pmssLighttpdPikkumiesHermesBlock('uitester', 24000), \pmssLighttpdHermesUiBlock('uitester', 24000),
            \pmssLighttpdHermesUiBlock('uitester', 24000, 'vanilla-agentboard')] as $block) {
            $this->assertStringNotContainsString('X-Forwarded-Proto', $block);
        }
    }
}
