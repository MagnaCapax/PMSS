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

    public function testPublicProxyBlockRewritesSabnzbdRedirects(): void
    {
        $this->assertStringContainsString('proxy_redirect ~^(https?://[^/]+)?/sabnzbd(/.*)?$ /public-##username/sabnzbd$2;', $this->publicProxyBlock());
    }

    public function testPublicProxyBlockRewritesSabnzbdCookiePaths(): void
    {
        $this->assertStringContainsString('proxy_cookie_path /sabnzbd /public-##username/sabnzbd;', $this->publicProxyBlock());
    }

    public function testPublicProxyBlockRewritesLidarrRedirects(): void
    {
        $this->assertStringContainsString('proxy_redirect ~^(https?://[^/]+)?/lidarr(/.*)?$ /public-##username/lidarr$2;', $this->publicProxyBlock());
    }

    public function testPublicProxyBlockRewritesLidarrCookiePaths(): void
    {
        $this->assertStringContainsString('proxy_cookie_path /lidarr /public-##username/lidarr;', $this->publicProxyBlock());
    }

    public function testPublicProxyBlockRewritesRadarrRedirects(): void
    {
        $this->assertStringContainsString('proxy_redirect ~^(https?://[^/]+)?/radarr(/.*)?$ /public-##username/radarr$2;', $this->publicProxyBlock());
    }

    public function testPublicProxyBlockRewritesRadarrCookiePaths(): void
    {
        $this->assertStringContainsString('proxy_cookie_path /radarr /public-##username/radarr;', $this->publicProxyBlock());
    }

    public function testPublicProxyBlockRewritesProwlarrRedirects(): void
    {
        $this->assertStringContainsString('proxy_redirect ~^(https?://[^/]+)?/prowlarr(/.*)?$ /public-##username/prowlarr$2;', $this->publicProxyBlock());
    }

    public function testPublicProxyBlockRewritesProwlarrCookiePaths(): void
    {
        $this->assertStringContainsString('proxy_cookie_path /prowlarr /public-##username/prowlarr;', $this->publicProxyBlock());
    }

    public function testPublicProxyBlockRewritesReadarrRedirects(): void
    {
        $this->assertStringContainsString('proxy_redirect ~^(https?://[^/]+)?/readarr(/.*)?$ /public-##username/readarr$2;', $this->publicProxyBlock());
    }

    public function testPublicProxyBlockRewritesReadarrCookiePaths(): void
    {
        $this->assertStringContainsString('proxy_cookie_path /readarr /public-##username/readarr;', $this->publicProxyBlock());
    }

    public function testPublicProxyBlockRewritesSonarrRedirects(): void
    {
        $this->assertStringContainsString('proxy_redirect ~^(https?://[^/]+)?/sonarr(/.*)?$ /public-##username/sonarr$2;', $this->publicProxyBlock());
    }

    public function testPublicProxyBlockRewritesSonarrCookiePaths(): void
    {
        $this->assertStringContainsString('proxy_cookie_path /sonarr /public-##username/sonarr;', $this->publicProxyBlock());
    }

    public function testPublicProxyBlockRewritesJellyfinRedirects(): void
    {
        $this->assertStringContainsString('proxy_redirect ~^(https?://[^/]+)?/jellyfin(/.*)?$ /public-##username/jellyfin$2;', $this->publicProxyBlock());
    }

    public function testPublicProxyBlockRewritesJellyfinCookiePaths(): void
    {
        $this->assertStringContainsString('proxy_cookie_path /jellyfin /public-##username/jellyfin;', $this->publicProxyBlock());
    }

    public function testProxyParamsForwardOriginalScheme(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.nginx-proxy_params');

        $this->assertStringContainsString('proxy_set_header X-Forwarded-Proto $scheme;', $template);
    }
}
