<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/testing/customerPanelRenderEnvironment.php';
require_once dirname(__DIR__, 2).'/testing/customerPanelRenderProcess.php';
require_once __DIR__.'/../common/TestCase.php';

class StatsDockerPolicyFrontendTest extends TestCase
{
    public function testStatsPageKeepsDockerPolicyReadOnlyInsideCustomerTree(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'etc/skel/www/stats.php',
            ['Docker policy:', 'Docker policy changes are handled by platform tooling.', 'Platform managed'],
            ['function pmssInfoDockerPolicy'.'StoreState()', 'pmssInfoSetDocker'.'Enabled', '/scripts/lib/user/userConfigStore.php']
        );
    }

    public function testStatsRenderShowsReadOnlyDockerPolicy(): void
    {
        $repoRoot = dirname(__DIR__, 4);
        $sourceWww = $repoRoot.'/etc/skel/www';
        $runRoot = \pmssCustomerPanelRenderTempRoot();
        $homeRoot = $runRoot.'/home';
        $home = $homeRoot.'/renderuser';
        $www = $home.'/www';
        $bootstrap = $runRoot.'/php-cli-bootstrap.php';

        try {
            $setup = \pmssCustomerPanelRenderPrepare($sourceWww, $home, $www, $bootstrap);
            $this->assertTrue($setup['ok'], $setup['error']);

            $result = \pmssCustomerPanelRenderPage($www, $bootstrap, $homeRoot, $home, 'stats.php', [
                'minBytes' => 5000,
                'query' => '',
            ]);
            $this->assertEquals([], $result['errors'], implode('; ', $result['errors']));
            $this->assertStringContainsString('Docker policy:', $result['stdout']);
            $this->assertStringContainsString('Platform managed', $result['stdout']);
            $this->assertStringNotContainsString('docker_toggle_state', $result['stdout']);
        } finally {
            \pmssCustomerPanelRenderCleanup($runRoot);
        }
    }
}
