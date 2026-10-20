<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class StatsAppToggleFrontendTest extends TestCase
{
    public function testStatsPagePointsToConfigureAfterDelivery(): void
    {
        $html = $this->pmssRenderCustomerPanelPage('stats.php');
        $this->assertStringContainsString('Manage in Configure', $html);
        $this->assertStringNotContainsString('data-action="disable"', $html);
    }

    public function testStatsPageShowsInlineEnableTogglesForCustomerManagedApps(): void
    {
        $html = $this->pmssRenderLegacyStats();
        require_once $this->pmssRepoPath('etc/skel/www/scriptsInc.php');
        foreach (pmssCustomerManagedAppDefinitions() as $app => $definition) {
            // Legacy Stats offers installed binaries; installable rows live in Configure.
            if (!empty($definition['installable'])) continue;
            $this->assertStringContainsString('data-app="'.$app.'"', $html);
            $this->assertStringContainsString('data-endpoint="'.$definition['endpoint'].'"', $html);
        }
        $this->assertStringNotContainsString('data-app="openclaw"', $html);

        $this->assertStringContainsAndOmitsStrings(['class="status-actions"', 'data-action="start"'], ['data-endpoint="rtorrent', 'data-endpoint="docker'], $html);
    }

    public function testStatsPageShowsDisableTogglesWhenEnableFlagsExist(): void
    {
        $html = $this->pmssRenderLegacyStats(array('.qbittorrentEnable', '.delugeEnable', '.rcloneEnable'));

        $this->assertEquals(3, substr_count($html, 'data-action="disable"'));
        $this->assertStringContainsAllStrings(['aria-label="Disable qBittorrent"', 'aria-label="Disable Deluge"', 'aria-label="Disable rclone"'], $html);
    }

    private function pmssRenderLegacyStats(array $flags = array()): string
    {
        return $this->pmssWithCustomerPanelRender(function (string $home, callable $render) use ($flags): string {
            $this->assertTrue(unlink($home.'/www/configureSettings.php'));
            foreach ($flags as $flag) $this->pmssWriteFile($home.'/'.$flag, '');
            return $render('stats.php', array('minBytes' => 5000))['stdout'];
        });
    }
}
