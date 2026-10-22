<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class CustomerPanelRenderHarnessTest extends TestCase
{
    public function testWelcomeMovesControlsOnlyAfterConfigureDelivery(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            $new = $render('welcome.php', array('minBytes' => 10000))['stdout'];
            $this->assertStringContainsString('href="configure.php#apps"', $new);
            $this->assertStringNotContainsString('id="h-legacy-title"', $new);
            $this->assertStringNotContainsString('<h6>Usage alert email</h6>', $new);
            $this->assertTrue(unlink($home.'/www/configureSettings.php'));
            $old = $render('welcome.php', array('minBytes' => 10000))['stdout'];
            $this->assertStringContainsString('<h2 id="h-legacy-title" class="h-label">Apps and settings</h2>', $old);
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

    public function testWelcomeTrafficMetricShowsUsedOfLimitOnce(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {

            $trafficData = array('raw' => array('month' => 35240 * 1024, 'week' => 0, 'day' => 0), 'daily' => array());
            $this->pmssWriteSerializedFixture($home.'/.trafficData', $trafficData);
            $this->pmssWriteSerializedFixture($home.'/.trafficDataIngress', array('raw' => array('month' => 0), 'daily' => array()));
            $this->pmssWriteFile($home.'/.trafficLimit', "100000\n");
            $this->pmssWriteFile($home.'/.bonusTraffic', "0\n");

            $expectations = \pmssCustomerPanelRenderExpectations();
            $result = $render('welcome.php', $expectations['welcome.php']);

            $this->assertStringContainsString('<div class="h-metric-v">35,240 GiB<span>of 100,000 GiB</span></div>', $result['stdout']);
            $this->assertSame(1, substr_count($result['stdout'], 'of 100,000 GiB'));
            $this->assertStringNotContainsString('>35240 GiB / 100000 GiB</span>', $result['stdout']);
        });
    }

    /** A real request runs the page top to bottom: the AI credits card must render with a key present (ADR 0148). */
    public function testWelcomeRendersAiCreditsCardWhenTheAccountHasAKey(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            $key = $this->pmssWriteFile($home.'/.virsilipasKey', "endpoint=https://virsilipas.mcx.fi/v1\nkey=vsk-".str_repeat('a', 24)."\n");
            $this->assertTrue(chmod($key, 0600));
            $expectations = \pmssCustomerPanelRenderExpectations();
            $result = $render('welcome.php', $expectations['welcome.php']);
            $this->assertStringContainsString('id="h-credits"', $result['stdout']);
            $this->assertStringContainsString('class="h-metrics h-m5"', $result['stdout']);
            $this->assertStringNotContainsString('vsk-', $result['stdout']);

            $this->assertTrue(chmod($key, 0644));
            $loose = $render('welcome.php', $expectations['welcome.php']);
            $this->assertStringNotContainsString('id="h-credits"', $loose['stdout']);
        });
    }
    public function testWelcomeTrafficUsageRendersWhenLimitFileIsMissing(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            @unlink($home.'/.trafficLimit');

            $result = $render('welcome.php',
                ['minBytes' => 1024, 'markers' => ['Traffic · 30 days']]
            );

            $this->assertStringContainsString('<div class="h-metric-v">15 GiB<span>out · unlimited</span></div>', $result['stdout']);
            $this->assertStringNotContainsString('Over your monthly traffic limit', $result['stdout']);
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
                    ['minBytes' => 1024, 'markers' => ['Traffic · 30 days']]
                );
            });

            $this->assertStringContainsString('full port speed', $result['stdout']);
            $this->assertStringNotContainsString('<b>speed reduced</b>', $result['stdout']);
            $this->assertStringNotContainsString('Traffic speed is reduced', $result['stdout']);
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
                    ['minBytes' => 1024, 'markers' => ['Traffic · 30 days']]
                );
            });

            $this->assertStringContainsString('Traffic speed is reduced', $result['stdout']);
            $this->assertStringContainsString('<b>speed reduced</b>', $result['stdout']);
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
                    ['minBytes' => 1024, 'markers' => ['Traffic · 30 days']]
                );
            });

            $this->assertStringContainsString('Over your monthly traffic limit', $result['stdout']);
            $this->assertStringContainsString('bandwidth is limited to 100 Mbps.', $result['stdout']);
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
