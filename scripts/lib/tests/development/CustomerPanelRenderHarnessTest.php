<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class CustomerPanelRenderHarnessTest extends TestCase
{
    public function testRendersCurrentCustomerPanelWithoutPhpErrors(): void
    {
        $run = $this->pmssCustomerPanelRenderHarnessRun();

        $this->assertSame(0, $run['result']['rc']);
        $this->assertStringContainsString('[customer-panel-render-harness] OK', $run['stderr']);
        $payload = $this->pmssDecodeJsonArray($run['result']['output']);
        $this->assertSame(true, $payload['ok']);
        $this->assertSame(3, count($payload['pages']));
        $this->assertFalse(is_dir($payload['environmentRoot']), 'Harness must clean its synthetic customer home');
    }

    public function testWelcomeTrafficGaugeOmitsDuplicateUsedLimitFooter(): void
    {
        $this->pmssLoadCustomerPanelRenderHarness();
        $runRoot = \pmssCustomerPanelRenderTempRoot();
        $homeRoot = $runRoot.'/home';
        $home = $homeRoot.'/renderuser';
        $www = $home.'/www';
        $bootstrap = $runRoot.'/php-cli-bootstrap.php';

        try {
            $setup = \pmssCustomerPanelRenderPrepare($this->pmssRepoPath('etc/skel/www'), $home, $www, $bootstrap);
            $this->assertTrue($setup['ok'], $setup['error']);

            $trafficData = array('raw' => array('month' => 35240 * 1024, 'week' => 0, 'day' => 0), 'daily' => array());
            $this->pmssWriteSerializedFixture($home.'/.trafficData', $trafficData);
            $this->pmssWriteSerializedFixture($home.'/.trafficDataIngress', array('raw' => array('month' => 0), 'daily' => array()));
            $this->pmssWriteFile($home.'/.trafficLimit', "100000\n");
            $this->pmssWriteFile($home.'/.bonusTraffic', "0\n");

            $expectations = \pmssCustomerPanelRenderExpectations();
            $result = \pmssCustomerPanelRenderPage($www, $bootstrap, $homeRoot, $home, 'welcome.php', $expectations['welcome.php']);
            $this->assertEquals(array(), $result['errors'], implode('; ', $result['errors']));
            $this->assertStringContainsString('Used: 35240 GiB / Limit: 100,000 GiB (30-day window)', $result['stdout']);
            $this->assertStringNotContainsString('>35240 GiB / 100000 GiB</span>', $result['stdout']);
        } finally {
            \pmssCustomerPanelRenderCleanup($runRoot);
        }
    }

    public function testWelcomeTrafficUsageRendersWhenLimitFileIsMissing(): void
    {
        $this->pmssLoadCustomerPanelRenderHarness();
        $runRoot = \pmssCustomerPanelRenderTempRoot();
        $homeRoot = $runRoot.'/home';
        $home = $homeRoot.'/renderuser';
        $www = $home.'/www';
        $bootstrap = $runRoot.'/php-cli-bootstrap.php';

        try {
            $setup = \pmssCustomerPanelRenderPrepare($this->pmssRepoPath('etc/skel/www'), $home, $www, $bootstrap);
            $this->assertTrue($setup['ok'], $setup['error']);
            @unlink($home.'/.trafficLimit');

            $result = \pmssCustomerPanelRenderPage(
                $www,
                $bootstrap,
                $homeRoot,
                $home,
                'welcome.php',
                ['minBytes' => 1024, 'markers' => ['Traffic Info']]
            );

            $this->assertEquals(array(), $result['errors'], implode('; ', $result['errors']));
            $this->assertStringContainsString('Traffic used (30 days): 15 GiB', $result['stdout']);
            $this->assertStringContainsString('Traffic limit: Unlimited', $result['stdout']);
        } finally {
            \pmssCustomerPanelRenderCleanup($runRoot);
        }
    }

    public function testWelcomeIgnoresOrphanedThrottleWithoutEnabledMarker(): void
    {
        $this->pmssLoadCustomerPanelRenderHarness();
        $runRoot = \pmssCustomerPanelRenderTempRoot();
        $homeRoot = $runRoot.'/home';
        $home = $homeRoot.'/renderuser';
        $www = $home.'/www';
        $bootstrap = $runRoot.'/php-cli-bootstrap.php';
        $stateDir = $this->pmssEnsureDir($runRoot.'/runtime/trafficLimits', 0700);

        try {
            $setup = \pmssCustomerPanelRenderPrepare($this->pmssRepoPath('etc/skel/www'), $home, $www, $bootstrap);
            $this->assertTrue($setup['ok'], $setup['error']);
            $this->pmssWriteFile($home.'/.throttle', "25\n");
            $this->pmssWriteFile($home.'/.trafficLimit', "100000\n");

            $result = [];
            $this->pmssWithEnv(['PMSS_TRAFFIC_LIMIT_STATE_DIR' => $stateDir], function () use (&$result, $www, $bootstrap, $homeRoot, $home): void {
                $result = \pmssCustomerPanelRenderPage(
                    $www,
                    $bootstrap,
                    $homeRoot,
                    $home,
                    'welcome.php',
                    ['minBytes' => 1024, 'markers' => ['Traffic Info']]
                );
            });

            $this->assertEquals(array(), $result['errors'], implode('; ', $result['errors']));
            $this->assertStringContainsString('Current effective: full plan port speed', $result['stdout']);
            $this->assertStringNotContainsString('Current effective: 25 Mbps (reduced)', $result['stdout']);
            $this->assertStringNotContainsString('Throttle cooldown active', $result['stdout']);
        } finally {
            \pmssCustomerPanelRenderCleanup($runRoot);
        }
    }

    public function testWelcomeShowsReducedThrottleWhenEnabledMarkerExists(): void
    {
        $this->pmssLoadCustomerPanelRenderHarness();
        $runRoot = \pmssCustomerPanelRenderTempRoot();
        $homeRoot = $runRoot.'/home';
        $home = $homeRoot.'/renderuser';
        $www = $home.'/www';
        $bootstrap = $runRoot.'/php-cli-bootstrap.php';
        $stateDir = $this->pmssEnsureDir($runRoot.'/runtime/trafficLimits', 0700);

        try {
            $setup = \pmssCustomerPanelRenderPrepare($this->pmssRepoPath('etc/skel/www'), $home, $www, $bootstrap);
            $this->assertTrue($setup['ok'], $setup['error']);
            $this->pmssWriteFile($home.'/.throttle', "25\n");
            $this->pmssWriteFile($home.'/.trafficLimit', "100000\n");
            $this->pmssWriteFile($stateDir.'/renderuser.enabled', "1\n");

            $result = [];
            $this->pmssWithEnv(['PMSS_TRAFFIC_LIMIT_STATE_DIR' => $stateDir], function () use (&$result, $www, $bootstrap, $homeRoot, $home): void {
                $result = \pmssCustomerPanelRenderPage(
                    $www,
                    $bootstrap,
                    $homeRoot,
                    $home,
                    'welcome.php',
                    ['minBytes' => 1024, 'markers' => ['Traffic Info']]
                );
            });

            $this->assertEquals(array(), $result['errors'], implode('; ', $result['errors']));
            $this->assertStringContainsString('Current effective: 25 Mbps (reduced)', $result['stdout']);
            $this->assertStringContainsString('Throttle cooldown active', $result['stdout']);
        } finally {
            \pmssCustomerPanelRenderCleanup($runRoot);
        }
    }

    public function testReportsUndefinedFunctionFatalFromFixture(): void
    {
        $root = $this->pmssMakeTempDir('pmss-render-fixture-', 0700);
        $this->pmssWriteRelativeFile($root, 'etc/skel/www/welcome.php', "<?php\npmssMissingCustomerHelper();\n");
        $this->pmssWriteRelativeFile($root, 'etc/skel/www/info.php', "<?php\necho str_repeat('i', 16);\n");
        $this->pmssWriteRelativeFile($root, 'etc/skel/www/stats.php', "<?php\necho str_repeat('s', 16);\n");

        $run = $this->pmssCustomerPanelRenderHarnessRun([
            'PMSS_CUSTOMER_PANEL_RENDER_ROOT' => $root,
            'PMSS_CUSTOMER_PANEL_RENDER_MIN_BYTES' => '1',
            'PMSS_CUSTOMER_PANEL_RENDER_DISABLE_MARKERS' => '1',
        ]);

        $this->assertSame(1, $run['result']['rc']);
        $this->assertStringContainsString('[customer-panel-render-harness] FAIL', $run['stderr']);
        $this->assertStringContainsString('Call to undefined function', $run['stderr']);
        $payload = $this->pmssDecodeJsonArray($run['result']['output']);
        $this->assertSame(false, $payload['ok']);
        $this->assertFalse(is_dir($payload['environmentRoot']), 'Harness must clean failed synthetic customer homes');
    }

    private function pmssCustomerPanelRenderHarnessRun(array $environment = []): array
    {
        $run = $this->pmssRunRepoPhpScriptCommandWithTempStderr(
            'scripts/testing/customer-panel-render-harness.php',
            [],
            $environment,
            'pmss-render-stderr-'
        );
        $run['stderr'] = (string) @file_get_contents($run['stderrPath']);
        return $run;
    }
}
