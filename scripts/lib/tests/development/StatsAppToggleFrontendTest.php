<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/testing/customerPanelRenderEnvironment.php';
require_once dirname(__DIR__, 2).'/testing/customerPanelRenderProcess.php';
require_once __DIR__.'/../common/TestCase.php';

class StatsAppToggleFrontendTest extends TestCase
{
    public function testStatsPageShowsInlineEnableTogglesForCustomerManagedApps(): void
    {
        $html = $this->renderStatsPage();

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
        $html = $this->renderStatsPage(array('.qbittorrentEnable', '.delugeEnable', '.rcloneEnable'));

        $this->assertEquals(3, substr_count($html, 'data-action="disable"'));
        $this->assertStringContainsString('aria-label="Disable qBittorrent"', $html);
        $this->assertStringContainsString('aria-label="Disable Deluge"', $html);
        $this->assertStringContainsString('aria-label="Disable rclone"', $html);
    }

    private function renderStatsPage(array $flags = array()): string
    {
        $sourceWww = $this->pmssRepoPath('etc/skel/www');
        $runRoot = \pmssCustomerPanelRenderTempRoot();
        $homeRoot = $runRoot.'/home';
        $home = $homeRoot.'/renderuser';
        $www = $home.'/www';
        $bootstrap = $runRoot.'/php-cli-bootstrap.php';

        try {
            $setup = \pmssCustomerPanelRenderPrepare($sourceWww, $home, $www, $bootstrap);
            $this->assertTrue($setup['ok'], $setup['error']);
            foreach ($flags as $flag) {
                touch($home.'/'.$flag);
            }

            $result = \pmssCustomerPanelRenderPage($www, $bootstrap, $homeRoot, $home, 'stats.php', array(
                'minBytes' => 5000,
                'query' => '',
            ));
            $this->assertEquals(array(), $result['errors'], implode('; ', $result['errors']));
            return $result['stdout'];
        } finally {
            \pmssCustomerPanelRenderCleanup($runRoot);
        }
    }
}
