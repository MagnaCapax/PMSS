<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class StatsAppToggleFrontendTest extends TestCase
{
    public function testStatsPageShowsInlineEnableTogglesForCustomerManagedApps(): void
    {
        $html = $this->pmssRenderCustomerPanelPage('stats.php');
        require_once $this->pmssRepoPath('etc/skel/www/scriptsInc.php');
        foreach (pmssCustomerManagedAppDefinitions() as $app => $definition) {
            $this->assertStringContainsString('data-app="'.$app.'"', $html);
            $this->assertStringContainsString('data-endpoint="'.$definition['endpoint'].'"', $html);
        }

        $this->assertStringContainsAndOmitsStrings(['class="status-actions"', 'data-action="start"'], ['data-endpoint="rtorrent', 'data-endpoint="docker'], $html);
    }

    public function testStatsPageShowsDisableTogglesWhenEnableFlagsExist(): void
    {
        $html = $this->pmssRenderCustomerPanelPage('stats.php', array('.qbittorrentEnable', '.delugeEnable', '.rcloneEnable'));

        $this->assertEquals(3, substr_count($html, 'data-action="disable"'));
        $this->assertStringContainsAllStrings(['aria-label="Disable qBittorrent"', 'aria-label="Disable Deluge"', 'aria-label="Disable rclone"'], $html);
    }
}
