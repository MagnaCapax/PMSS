<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class CustomerPanelRenderHarnessTest extends TestCase
{
    public function testWelcomeMovesControlsOnlyAfterConfigureDelivery(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            $new = $render('welcome.php', array('minBytes' => 10000))['stdout'];
            $this->assertStringContainsAndOmitsStrings(['href="configure.php#apps"'],
                ['id="h-legacy-title"', '<h6>Usage alert email</h6>'], $new);
            $this->assertTrue(unlink($home.'/www/configureSettings.php'));
            $old = $render('welcome.php', array('minBytes' => 10000))['stdout'];
            $this->assertStringContainsAllStrings(['<h2 id="h-legacy-title" class="h-label">Apps and settings</h2>',
                '<h6>Usage alert email</h6>', '<h6>App passwords</h6>'], $old);
        });
    }

    public function testConfigurePageRendersWithoutPhpErrors(): void
    {
        $html = $this->pmssRenderCustomerPanelPage('configure.php', [], [
            'minBytes' => 4000,
        ]);
        $this->assertStringContainsAllStrings(['Find more apps', 'Media Stack is not installed',
            "$('details.catalog').prop('open', query !== '')"], $html);
    }

    public function testAppsStatusEndpointSurvivesConfigureDelivery(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            $status = $render('apps.php', array('query' => 'status=1', 'minBytes' => 100))['stdout'];
            $this->pmssDecodeJsonArray($status);
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
            $this->assertStringContainsAndOmitsStrings(['id="h-credits"', 'class="h-metrics h-m5"'],
                ['vsk-'], $result['stdout']);

            $this->assertTrue(chmod($key, 0644));
            $loose = $render('welcome.php', $expectations['welcome.php']);
            $this->assertStringNotContainsString('id="h-credits"', $loose['stdout']);
        });
    }

    /** The credits request answers before the page's helpers and state reads, so output or a stall there cannot sit in front of its JSON. */
    public function testWelcomeCreditsRequestAnswersPureJsonBeforeThePageReads(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            $key = 'vsk-'.str_repeat('a', 24);
            $keyFile = $this->pmssWriteFile($home.'/.virsilipasKey', "endpoint=https://virsilipas.mcx.fi/v1\nkey=".$key."\n");
            $this->assertTrue(chmod($keyFile, 0600));
            $this->pmssWriteFile($home.'/.cache/pmss-welcome/credits.json', (string) json_encode(array(
                'at' => time(), 'key' => substr(hash('sha256', $key), 0, 12),
                'figures' => array('balance' => 900000, 'tokens_30d' => 1234567, 'pct_left' => 45.5))), 0700);
            // Helpers the page loads and runs before building its state: one that prints text and one that never returns quickly.
            $this->pmssWriteFile($home.'/www/welcomeAnnouncements.php', '<?php echo "STRAY OUTPUT";');
            $this->pmssWriteFile($home.'/www/userTrafficLimit.php', '<?php sleep(30);');

            $started = microtime(true);
            $result = $render('welcome.php', array('query' => 'credits=1', 'minBytes' => 10));
            $this->assertTrue(microtime(true) - $started < 15, 'The credits request must not wait for the page reads');
            $credits = $this->pmssDecodeJsonArray($result['stdout'], 'The credits response must be JSON alone: '.substr($result['stdout'], 0, 120));
            $this->assertSame('ok', $credits['state']);
            $this->assertSame('900k', $credits['value']);
            $this->assertStringNotContainsString($key, $result['stdout']);

            $this->assertTrue(unlink($keyFile));
            $none = json_decode($render('welcome.php', array('query' => 'credits=1', 'minBytes' => 10))['stdout'], true);
            $this->assertSame('none', $none['state'], 'An account without a key gets a state, not an empty answer');
        });
    }

    /** A credits read that fails, stalls or answers with anything but JSON removes the card; it never stays on its placeholder (ADR 0148). */
    public function testWelcomeCreditsCardLeavesNoPlaceholderWhenTheReadFails(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            $key = $this->pmssWriteFile($home.'/.virsilipasKey', "endpoint=https://virsilipas.mcx.fi/v1\nkey=vsk-".str_repeat('a', 24)."\n");
            $this->assertTrue(chmod($key, 0600));
            $expectations = \pmssCustomerPanelRenderExpectations();
            $page = $render('welcome.php', $expectations['welcome.php'])['stdout'];
            $this->assertStringContainsString('function pmssCreditsCardDrop()', $page);
            $this->assertStringContainsString('setTimeout(pmssCreditsCardDrop, 20000)', $page, 'A request that never answers must not leave the card waiting');
            $this->assertStringContainsAllStrings(["if (!response.ok) throw new Error('credits ' + response.status);",
                '.catch(function () { clearTimeout(watchdog); pmssCreditsCardDrop(); })'], $page);
            // The card's script is its own element: an error in the page script above it cannot stop it from running.
            $creditsScript = null;
            foreach (explode('<script', $page) as $script) {
                if (strpos($script, "getElementById('h-credits')") !== false) { $creditsScript = $script; }
            }
            $this->assertTrue($creditsScript !== null, 'The credits card must have a script');
            $this->assertStringNotContainsString('.catch(function () {})', $creditsScript, 'A swallowed failure leaves the card on "reading the balance"');
            $this->assertStringNotContainsString('/* Welcome home. */', $creditsScript, 'The credits card must not share a script element with the page script');
            $this->assertStringContainsString('News is unavailable right now.', $page, 'A News request that fails must end in words, not in "Loading the latest news"');
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
                '<input type="radio" name="topic" value="working" checked><span>Not working</span>',
                '<input type="radio" name="topic" value="slow"><span>Slow</span>',
                '<input type="radio" name="topic" value="down"><span>Server down</span>',
                '<input type="radio" name="topic" value="billing"><span>Billing / account</span>',
                '<input name="email" type="hidden" value="render@example.test">',
            );

            $keyless = $render('welcome.php', $expectations['welcome.php'])['stdout'];
            $this->assertStringContainsAllStrings($form, $keyless);
            $this->assertSame(4, substr_count($keyless, 'name="topic"'));
            $this->assertStringContainsAndOmitsStrings(['Opens in the client area.'], ['vsk-'], $keyless);

            $key = $this->pmssWriteFile($home.'/.virsilipasKey', "endpoint=https://virsilipas.mcx.fi/v1\nkey=vsk-".str_repeat('a', 24)."\n");
            $this->assertTrue(chmod($key, 0600));
            $keyed = $render('welcome.php', $expectations['welcome.php'])['stdout'];
            $this->assertStringContainsAllStrings($form, $keyed);
            $this->assertStringContainsAndOmitsStrings([], ['Opens in the client area.', 'vsk-'], $keyed);

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

    /**
     * Get help, step two: the form offers Pikkumies first only where the ask bar shows AND the account has an AI key.
     * Without the stub the real state reader stays hidden: the synthetic home has no ~/.mcxPikkumies command.
     */
    public function testWelcomeOffersPikkumiesFirstOnlyWithTheBarAndAKey(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            $expectations = \pmssCustomerPanelRenderExpectations();
            $pikkumiesFirst = array(
                'data-pikkumies="1"',
                'id="h-support-pikkumies"',
                '<button type="button" class="h-support-skip" onclick="return pmssSupportSend(this.form, true)">Send to support instead</button>',
                'id="h-support-activity"',
            );
            $assertPlainForm = function (string $html, string $case): void {
                $this->assertStringContainsString('id="h-support-form"', $html, $case);
                $this->assertStringContainsString('data-pikkumies="0"', $html, $case);
                foreach (array('data-pikkumies="1"', 'id="h-support-pikkumies"', 'class="h-support-skip"',
                    'Send to support instead', 'id="h-support-activity"') as $needle) {
                    $this->assertStringNotContainsString($needle, $html, $case.': '.$needle);
                }
            };

            $key = $this->pmssWriteFile($home.'/.virsilipasKey', "endpoint=https://virsilipas.mcx.fi/v1\nkey=vsk-".str_repeat('a', 24)."\n");
            $this->assertTrue(chmod($key, 0600));
            $hidden = $render('welcome.php', $expectations['welcome.php'])['stdout'];
            $this->assertStringNotContainsString('id="pmss-pikkumies-bar"', $hidden, 'No ask bar without the stub');
            $assertPlainForm($hidden, 'key without the bar');

            $panel = $home.'/www/pikkumiesPanel.php';
            $source = (string) file_get_contents($panel);
            $this->assertStringContainsString('function pmssPikkumiesPanelStateRead(', $source);
            $this->pmssWriteFile($panel, preg_replace('/\?>\s*$/', '', str_replace(
                'function pmssPikkumiesPanelStateRead(', 'function pmssPikkumiesPanelStateReadReal(', $source))
                ."\nfunction pmssPikkumiesPanelStateRead(\$home, \$name) { return array('visible' => true, 'origin' => 'https://example.test', 'mode' => 'simplified', 'off' => false); }\n");

            $offered = $render('welcome.php', $expectations['welcome.php'])['stdout'];
            $this->assertStringContainsString('id="pmss-pikkumies-bar"', $offered);
            $this->assertStringContainsAllStrings($pikkumiesFirst, $offered);
            $this->assertStringContainsAndOmitsStrings([], ['data-pikkumies="0"', 'vsk-'], $offered);

            $this->assertTrue(unlink($key));
            $keyless = $render('welcome.php', $expectations['welcome.php'])['stdout'];
            $this->assertStringContainsString('id="pmss-pikkumies-bar"', $keyless, 'The bar still shows without a key');
            $assertPlainForm($keyless, 'bar without a key');
            $this->assertStringContainsString('Opens in the client area.', $keyless);
        });
    }

    public function testWelcomeTrafficUsageRendersWhenLimitFileIsMissing(): void
    {
        $this->pmssWithCustomerPanelRender(function (string $home, callable $render): void {
            @unlink($home.'/.trafficLimit');

            $result = $render('welcome.php',
                ['minBytes' => 1024, 'markers' => ['Traffic · 30 days']]
            );

            $this->assertStringContainsAndOmitsStrings(['<div class="h-metric-v">15 GiB<span>out · unlimited</span></div>'],
                ['Over your monthly traffic limit'], $result['stdout']);
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

            $this->assertStringContainsAndOmitsStrings(['full port speed'], ['<b>speed reduced</b>',
                'Traffic speed is reduced', 'Current effective: 25 Mbps (reduced)', 'Throttle cooldown active'], $result['stdout']);
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

            $this->assertStringContainsAllStrings(['Traffic speed is reduced', '<b>speed reduced</b>',
                'Current effective: 25 Mbps (reduced)', 'Throttle cooldown active'], $result['stdout']);
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

            $this->assertStringContainsAndOmitsStrings(['Over your monthly traffic limit',
                'bandwidth is limited to 100 Mbps.', 'Current effective: 100 Mbps (reduced)',
                'Throttled to 100 Mbps'], ['Current effective: full plan port speed',
                'Approaching monthly traffic cap'], $result['stdout']);
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
        $this->assertStringContainsAllStrings(['[customer-panel-render-harness] FAIL', 'Call to undefined function'], $run['stderr']);
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
