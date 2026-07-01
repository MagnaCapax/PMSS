<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class MediaStackNginxRedirectTest extends TestCase
{
    private const PUBLIC_SESSION_COOKIE_APPS = [
        'sabnzbd',
        'lidarr',
        'radarr',
        'prowlarr',
        'readarr',
        'sonarr',
        'jellyfin',
        'komga',
    ];

    private function publicProxyBlock(): string
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-user');
        $matches = array();
        preg_match('/location \/public-##username\/ \{.*?\n\}/s', $template, $matches);
        $this->assertTrue(isset($matches[0]), 'Expected /public nginx location block');

        return $matches[0];
    }

    public function testPublicProxyBlockDoesNotCarryAppSpecificRedirectRules(): void
    {
        $block = $this->publicProxyBlock();

        foreach (self::PUBLIC_SESSION_COOKIE_APPS as $app) {
            $this->assertFalse(
                strpos($block, 'proxy_redirect ~^(https?://[^/]+)?/'.$app.'(/.*)?$') !== false,
                'Nginx public block must stay app-agnostic for '.$app.' redirects'
            );
        }
    }

    public function testPublicProxyBlockRewritesPublicSessionCookiePaths(): void
    {
        $block = $this->publicProxyBlock();

        foreach (self::PUBLIC_SESSION_COOKIE_APPS as $app) {
            $this->assertTrue(
                strpos($block, 'proxy_cookie_path /'.$app.' /public-##username/'.$app.';') !== false,
                'Nginx public block must restore cookie scope for '.$app
            );
        }
    }

    public function testPublicProxyBlockRewritesGenericRedirects(): void
    {
        $this->assertStringContainsString('proxy_redirect ~^(https?://[^/]+)?/(.+)$ /public-##username/$2;', $this->publicProxyBlock());
    }

    public function testPublicProxyBlockKeepsGenericRedirectWithoutAppSpecificRulesAheadOfIt(): void
    {
        $block = $this->publicProxyBlock();
        $genericRule = 'proxy_redirect ~^(https?://[^/]+)?/(.+)$ /public-##username/$2;';

        $this->assertStringContainsAndOmitsStrings([$genericRule], ['/jellyfin(/.*)?$' => 'Expected nginx public block to stay free of app-specific redirect rules'], $block);
    }

    public function testProxyParamsForwardOriginalScheme(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-proxy_params');

        $this->assertStringContainsString('proxy_set_header X-Forwarded-Proto $scheme;', $template);
    }
}
