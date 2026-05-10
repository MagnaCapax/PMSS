<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class MediaStackNginxRedirectTest extends TestCase
{
    private function publicProxyBlock(): string
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-user');
        $matches = array();
        preg_match('/location \/public-##username\/ \{.*?\n\}/s', $template, $matches);
        $this->assertTrue(isset($matches[0]), 'Expected /public nginx location block');

        return $matches[0];
    }

    public function testPublicProxyBlockDoesNotCarryMediaStackRedirectRules(): void
    {
        $block = $this->publicProxyBlock();

        foreach (array('sabnzbd', 'lidarr', 'radarr', 'prowlarr', 'readarr', 'sonarr', 'jellyfin') as $app) {
            $this->assertFalse(
                strpos($block, 'proxy_redirect ~^(https?://[^/]+)?/'.$app.'(/.*)?$') !== false,
                'Nginx public block must stay app-agnostic for '.$app.' redirects'
            );
        }
    }

    public function testPublicProxyBlockRewritesMediaStackCookiePaths(): void
    {
        $block = $this->publicProxyBlock();

        foreach (array('sabnzbd', 'lidarr', 'radarr', 'prowlarr', 'readarr', 'sonarr', 'jellyfin') as $app) {
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

        $this->assertTrue(strpos($block, $genericRule) !== false, 'Expected generic redirect rule in public block');
        $this->assertFalse(strpos($block, '/jellyfin(/.*)?$') !== false, 'Expected nginx public block to stay free of app-specific redirect rules');
    }

    public function testProxyParamsForwardOriginalScheme(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-proxy_params');

        $this->assertStringContainsString('proxy_set_header X-Forwarded-Proto $scheme;', $template);
    }
}
