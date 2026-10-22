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

    /** The Help card's Get help form renders with and without an AI key; only the key-less form says it opens in the client area. */
    public function testWelcomeRendersGetHelpFormWithAndWithoutAKey(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            $this->pmssWriteFile($home.'/.notifyEmail', "render@example.test\n");
            $expectations = \pmssCustomerPanelRenderExpectations();
            $form = array(
                'id="h-support-form"',
                'onsubmit="return pmssSupportSend(this)"',
                '<input type="radio" name="topic" value="working" checked> Not working',
                '<input type="radio" name="topic" value="slow"> Slow',
                '<input type="radio" name="topic" value="down"> Server down',
                '<input type="radio" name="topic" value="billing"> Billing / account',
                '<input id="h-support-email" name="email" type="email" required value="render@example.test">',
            );

            $keyless = $render('welcome.php', $expectations['welcome.php'])['stdout'];
            $this->assertStringContainsAllStrings($form, $keyless);
            $this->assertSame(4, substr_count($keyless, 'name="topic"'));
            $this->assertStringContainsString('Opens in the client area.', $keyless);
            $this->assertStringNotContainsString('vsk-', $keyless);

            $key = $this->pmssWriteFile($home.'/.virsilipasKey', "endpoint=https://virsilipas.mcx.fi/v1\nkey=vsk-".str_repeat('a', 24)."\n");
            $this->assertTrue(chmod($key, 0600));
            $keyed = $render('welcome.php', $expectations['welcome.php'])['stdout'];
            $this->assertStringContainsAllStrings($form, $keyed);
            $this->assertStringNotContainsString('Opens in the client area.', $keyed);
            $this->assertStringNotContainsString('vsk-', $keyed);

            // The billing service page link appears only when the account knows its service id (the harness seeds 12345).
            $this->assertStringContainsString('clientarea.php?action=productdetails&amp;id=12345" target="_blank" rel="noopener">Your service in the client area</a>', $keyed);
            $this->assertTrue(unlink($home.'/.billingServiceId') && unlink($home.'/.billingId'));
            $unbilled = $render('welcome.php', $expectations['welcome.php'])['stdout'];
            $this->assertStringNotContainsString('Your service in the client area', $unbilled);
        });
    }

    /**
     * An Eternals host shows the Pikkumies ask bar between Your account and Your apps.
     * The real state reader needs a root-owned host marker, so the copied customer tree's reader is stubbed.
     */
    public function testWelcomeRendersPikkumiesAskBarWhenVisible(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            $panel = $home.'/www/pikkumiesPanel.php';
            $this->assertTrue(is_file($home.'/www/pikkumiesOpen.php'), 'The bar is offered only next to pikkumiesOpen.php');
            $source = (string) file_get_contents($panel);
            $this->assertStringContainsString('function pmssPikkumiesPanelStateRead(', $source);
            $this->pmssWriteFile($panel, preg_replace('/\?>\s*$/', '', str_replace(
                'function pmssPikkumiesPanelStateRead(', 'function pmssPikkumiesPanelStateReadReal(', $source))
                ."\nfunction pmssPikkumiesPanelStateRead(\$home, \$name) { return array('visible' => true, 'origin' => 'https://example.test', 'mode' => 'simplified', 'off' => false); }\n");
            $expectations = \pmssCustomerPanelRenderExpectations();

            $html = $render('welcome.php', $expectations['welcome.php'])['stdout'];

            $this->assertStringContainsAllStrings(array(
                '<div class="pmss-pikkumies-bar" id="pmss-pikkumies-bar">',
                'Pikkumies is getting ready…</button>',
                'var pmssPikkumiesBarOrigin = "https:\/\/example.test";',
            ), $html);
            $account = strpos($html, 'id="h-status-title"');
            $bar = strpos($html, 'id="pmss-pikkumies-bar"');
            $apps = strpos($html, 'id="h-apps-title"');
            $this->assertTrue($account !== false && $bar !== false && $apps !== false, 'Account, bar and apps all render');
            $this->assertTrue($account < $bar && $bar < $apps, 'The ask bar sits after Your account and before Your apps');
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
