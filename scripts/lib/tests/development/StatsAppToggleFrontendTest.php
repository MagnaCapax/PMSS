<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class StatsAppToggleFrontendTest extends TestCase
{
    public function testStatsPageShowsInlineEnableTogglesForCustomerManagedApps(): void
    {
        $html = $this->pmssRenderCustomerPanelPage('stats.php');

        foreach (['qBittorrent' => 'qbittorrent.php', 'Deluge' => 'deluge.php', 'rclone' => 'rclone.php'] as $app => $endpoint) {
            $this->assertStringContainsString('data-app="'.$app.'"', $html);
            $this->assertStringContainsString('data-endpoint="'.$endpoint.'"', $html);
        }

        $this->assertStringContainsString('class="status-actions"', $html);
        $this->assertStringContainsString('data-action="start"', $html);
        $this->assertStringNotContainsString('data-endpoint="rtorrent', $html);
        $this->assertStringNotContainsString('data-endpoint="docker', $html);
    }

    public function testStatsPageShowsDisableTogglesWhenEnableFlagsExist(): void
    {
        $html = $this->pmssRenderCustomerPanelPage('stats.php', array('.qbittorrentEnable', '.delugeEnable', '.rcloneEnable'));

        $this->assertEquals(3, substr_count($html, 'data-action="disable"'));
        $this->assertStringContainsString('aria-label="Disable qBittorrent"', $html);
        $this->assertStringContainsString('aria-label="Disable Deluge"', $html);
        $this->assertStringContainsString('aria-label="Disable rclone"', $html);
    }
}
