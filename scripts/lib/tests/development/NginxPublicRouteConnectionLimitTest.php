<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/nginxConfig/templates.php';

/**
 * The per-address connection cap on the public web folder routes.
 *
 * `/public-##username/` and its `/~##username/` alias front the account's public folder and every
 * browser media app, so a page load, a second tab and a companion app share one per-address counter
 * on the `addr` zone. A cap of 8 refused ordinary sessions with nginx's stock 503, which browsers
 * treat as a fatal asset failure. The cap is 16 (the panel routes' own value, so no route outranks
 * the authenticated panel on the shared counter) and a refusal answers 429 so the cause is visible.
 * The same two directives live in three hand-written places; this test keeps them together.
 */
final class NginxPublicRouteConnectionLimitTest extends TestCase
{
    private const LIMIT = 16;

    /** Return the text of the first `location <selector> {` block, braces balanced. */
    private function locationBlock(string $config, string $selector): string
    {
        $start = strpos($config, 'location '.$selector.' {');
        $this->assertTrue($start !== false, 'missing location '.$selector);
        $depth = 0;
        for ($i = $start, $n = strlen($config); $i < $n; $i++) {
            if ($config[$i] === '{') {
                $depth++;
            } elseif ($config[$i] === '}' && --$depth === 0) {
                return substr($config, $start, $i - $start + 1);
            }
        }
        $this->fail('unbalanced location '.$selector);
    }

    /** @return list<string> every `limit_conn <zone> <n>;` and `limit_conn_status <n>;` directive in a block */
    private function limitDirectives(string $block): array
    {
        preg_match_all('/^\s*(limit_conn(?:_status)?\s+[^;]+;)/m', $block, $matches);
        return $matches[1];
    }

    public function testUserTemplatePublicRoutesShareTheCapAndAnswer429(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-user');
        foreach (['/public-##username/', '/~##username/'] as $selector) {
            $this->assertSame(
                ['limit_conn addr '.self::LIMIT.';', 'limit_conn_status 429;'],
                $this->limitDirectives($this->locationBlock($template, $selector)),
                $selector
            );
        }
    }

    public function testSubdomainProxyLocationsCarryTheSameCapAndStatus(): void
    {
        $templates = \pmssNginxUserSubdomainTemplates();
        $blocks = array_slice(explode('location / {', $templates['public']), 1);
        // One public proxy location per listener (:80 and :443); the :443 WebDAV location keeps its own cap.
        $this->assertSame(2, count($blocks));
        foreach ($blocks as $i => $tail) {
            $this->assertSame(
                ['limit_conn addr '.self::LIMIT.';', 'limit_conn_status 429;'],
                $this->limitDirectives($this->locationBlock('location / {'.$tail, '/')),
                'listener '.$i
            );
        }
    }

    public function testNoPublicRouteCopyKeepsTheOldCapOfEight(): void
    {
        $sources = [
            $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-user'),
            $this->pmssReadRepoFile('scripts/lib/nginxConfig/templates.php'),
            implode("\n", \pmssNginxUserSubdomainTemplates()),
        ];
        foreach ($sources as $source) {
            $this->assertStringNotContainsString('limit_conn addr 8;', $source);
        }
    }
}
