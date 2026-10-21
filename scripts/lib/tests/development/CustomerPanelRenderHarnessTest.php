<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class CustomerPanelRenderHarnessTest extends TestCase
{
    public function testWelcomeMovesControlsOnlyAfterConfigureDelivery(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            $new = $render('welcome.php', array('minBytes' => 10000))['stdout'];
            $this->assertStringContainsString('<h6>Apps and settings</h6>', $new);
            $this->assertStringNotContainsString('<h6>Usage alert email</h6>', $new);
            $this->assertTrue(unlink($home.'/www/configureSettings.php'));
            $old = $render('welcome.php', array('minBytes' => 10000))['stdout'];
            $this->assertStringContainsString('<h6>Usage alert email</h6>', $old);
            $this->assertStringContainsString('<h6>App passwords</h6>', $old);
        });
    }

    public function testConfigurePageRendersWithoutPhpErrors(): void
    {
        $html = $this->pmssRenderCustomerPanelPage('configure.php', [], [
            'minBytes' => 4000,
        ]);
        $this->assertStringContainsString('Find more apps', $html);
        $this->assertStringContainsString('Media Stack is not installed', $html);
        $this->assertStringContainsString("$('details.catalog').prop('open', query !== '')", $html);
        if (!is_file('/etc/seedbox/config/torrentClients.enabled')) {
            // A host without the opt-in marker shows no version picker or switch control.
            $this->assertStringNotContainsString('version</h', $html);
            $this->assertStringNotContainsString("pmssConfigureSend('torrentClientSwitch'", $html);
        }
    }

    public function testAppsStatusEndpointSurvivesConfigureDelivery(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            $status = $render('apps.php', array('query' => 'status=1', 'minBytes' => 100))['stdout'];
            $this->assertTrue(is_array(json_decode($status, true)));
            $this->assertStringContainsString('"apps"', $status);
        });
    }

    public function testRendersCurrentCustomerPanelWithoutPhpErrors(): void
    {
        $run = $this->pmssCustomerPanelRenderHarnessRun();

        $this->assertSame(0, $run['result']['rc']);
        $this->assertStringContainsString('[customer-panel-render-harness] OK', $run['stderr']);
        $payload = $this->pmssDecodeJsonArray($run['result']['output']);
        $this->assertSame(true, $payload['ok']);
        $this->assertSame(4, count($payload['pages']));
        $this->assertFalse(is_dir($payload['environmentRoot']), 'Harness must clean its synthetic customer home');
    }

    public function testWelcomeTrafficGaugeOmitsDuplicateUsedLimitFooter(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {

            $trafficData = array('raw' => array('month' => 35240 * 1024, 'week' => 0, 'day' => 0), 'daily' => array());
            $this->pmssWriteSerializedFixture($home.'/.trafficData', $trafficData);
            $this->pmssWriteSerializedFixture($home.'/.trafficDataIngress', array('raw' => array('month' => 0), 'daily' => array()));
            $this->pmssWriteFile($home.'/.trafficLimit', "100000\n");
            $this->pmssWriteFile($home.'/.bonusTraffic', "0\n");

            $expectations = \pmssCustomerPanelRenderExpectations();
            $result = $render('welcome.php', $expectations['welcome.php']);

            $this->assertStringContainsString('Used: 35240 GiB / Limit: 100,000 GiB (30-day window)', $result['stdout']);
            $this->assertStringNotContainsString('>35240 GiB / 100000 GiB</span>', $result['stdout']);
        });
    }

    public function testWelcomeTrafficUsageRendersWhenLimitFileIsMissing(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            @unlink($home.'/.trafficLimit');

            $result = $render('welcome.php',
                ['minBytes' => 1024, 'markers' => ['Traffic Info']]
            );

            $this->assertStringContainsString('Traffic used (30 days): 15 GiB', $result['stdout']);
            $this->assertStringContainsString('Traffic limit: Unlimited', $result['stdout']);
        });
    }

    public function testWelcomeIgnoresOrphanedThrottleWithoutEnabledMarker(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render, string $runRoot): void {
            $stateDir = $this->pmssEnsureDir($runRoot.'/runtime/trafficLimits', 0700);
            $this->pmssWriteFile($home.'/.throttle', "25\n");
            $this->pmssWriteFile($home.'/.trafficLimit', "100000\n");

            $result = [];
            $this->pmssWithEnv(['PMSS_TRAFFIC_LIMIT_STATE_DIR' => $stateDir], function () use (&$result, $render): void {
                $result = $render('welcome.php',
                    ['minBytes' => 1024, 'markers' => ['Traffic Info']]
                );
            });

            $this->assertStringContainsString('Current effective: full plan port speed', $result['stdout']);
            $this->assertStringNotContainsString('Current effective: 25 Mbps (reduced)', $result['stdout']);
            $this->assertStringNotContainsString('Throttle cooldown active', $result['stdout']);
        });
    }

    public function testWelcomeShowsReducedThrottleWhenEnabledMarkerExists(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render, string $runRoot): void {
            $stateDir = $this->pmssEnsureDir($runRoot.'/runtime/trafficLimits', 0700);
            $this->pmssWriteFile($home.'/.throttle', "25\n");
            $this->pmssWriteFile($home.'/.trafficLimit', "100000\n");
            $this->pmssWriteFile($stateDir.'/renderuser.enabled', "1\n");

            $result = [];
            $this->pmssWithEnv(['PMSS_TRAFFIC_LIMIT_STATE_DIR' => $stateDir], function () use (&$result, $render): void {
                $result = $render('welcome.php',
                    ['minBytes' => 1024, 'markers' => ['Traffic Info']]
                );
            });

            $this->assertStringContainsString('Current effective: 25 Mbps (reduced)', $result['stdout']);
            $this->assertStringContainsString('Throttle cooldown active', $result['stdout']);
        });
    }

    public function testWelcomeShowsReducedThrottleAtDefaultCapWhenEnabledMarkerExists(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render, string $runRoot): void {
            $stateDir = $this->pmssEnsureDir($runRoot.'/runtime/trafficLimits', 0700);
            $trafficData = array('raw' => array('month' => 125 * 1024, 'week' => 0, 'day' => 0), 'daily' => array());
            $this->pmssWriteSerializedFixture($home.'/.trafficData', $trafficData);
            $this->pmssWriteFile($home.'/.trafficLimit', "100\n");
            $this->pmssWriteFile($home.'/.bonusTraffic', "0\n");
            $this->pmssWriteFile($home.'/.throttle', "100\n");
            $this->pmssWriteFile($stateDir.'/renderuser.enabled', "1\n");

            $result = [];
            $this->pmssWithEnv(['PMSS_TRAFFIC_LIMIT_STATE_DIR' => $stateDir], function () use (&$result, $render): void {
                $result = $render('welcome.php',
                    ['minBytes' => 1024, 'markers' => ['Traffic Info']]
                );
            });

            $this->assertStringContainsString('OVER TRAFFIC LIMIT WARNING - REDUCED BANDWIDTH', $result['stdout']);
            $this->assertStringContainsString('Current effective: 100 Mbps (reduced)', $result['stdout']);
            $this->assertStringContainsString('Throttled to 100 Mbps', $result['stdout']);
            $this->assertStringNotContainsString('Current effective: full plan port speed', $result['stdout']);
            $this->assertStringNotContainsString('Approaching monthly traffic cap', $result['stdout']);
        });
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
