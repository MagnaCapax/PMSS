<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/util/userConfigLighttpd.php';

class LighttpdProxyFragmentsTest extends TestCase
{
    public function testTemplateDoesNotEmbedRcloneProxy(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.lighttpd');

        $this->assertTrue(strpos($template, '/user-##username/rclone/') === false, 'rclone proxy must be in custom fragment');
    }

    public function testTemplateDoesNotEmbedQbittorrentProxy(): void
    {
        $template = $this->pmssReadRepoFile('etc/seedbox/config/template.lighttpd');

        $this->assertTrue(strpos($template, '/user-##username/qbittorrent/') === false, 'qBittorrent proxy must be in custom fragment');
    }

    public function testRcloneFragmentContainsExpectedProxy(): void
    {
        $fragment = \pmssRcloneLighttpdProxyFragment('demo', 4001);

        $this->assertStringContainsString('^/user-demo/rclone/', $fragment);
        $this->assertStringContainsString('"host" => "127.0.0.1"', $fragment);
        $this->assertStringContainsString('"port" => 4001', $fragment);
    }

    public function testQbittorrentFragmentContainsExpectedProxy(): void
    {
        $fragment = \pmssQbittorrentLighttpdProxyFragment('demo', 4002);

        $this->assertStringContainsString('^/user-demo/qbittorrent/', $fragment);
        $this->assertStringContainsString('"host" => "127.0.0.1"', $fragment);
        $this->assertStringContainsString('"port" => 4002', $fragment);
        $this->assertStringContainsString('"/user-demo/qbittorrent/"  => "/"', $fragment);
        $this->assertStringContainsString('"/user-demo/qbittorrent" => ""', $fragment);
    }
}
