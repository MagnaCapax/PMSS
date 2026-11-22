<?php
/** Verify worker-floor CLI parsing, durable merging and targeted refresh wiring. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/userConfigCli.php';
require_once dirname(__DIR__, 2).'/user/userConfigRuntime.php';
require_once dirname(__DIR__, 2).'/cli/optionParser.php';
require_once dirname(__DIR__, 3).'/util/userConfigLighttpd.php';

class UserConfigLighttpdMinThreadsTest extends TestCase
{
    public function testParserAcceptsOnlyRepresentableNonNegativeIntegers(): void
    {
        $this->assertSame(null, \pmssUserConfigParseLighttpdMinThreadsOption(null));
        foreach (['0', '1', '13', '18', '48', '999', (string) PHP_INT_MAX] as $raw) {
            $this->assertSame((int) $raw, \pmssUserConfigParseLighttpdMinThreadsOption($raw));
        }
        foreach ([true, false, 18, 1.5, [], new \stdClass(), '', '-1', '+1', '01', '1.5', '1e3',
            ' 18', "18\n", "18\0", '18;id', (string) PHP_INT_MAX.'0'] as $raw) {
            $this->assertThrows(\InvalidArgumentException::class, static function () use ($raw): void {
                \pmssUserConfigParseLighttpdMinThreadsOption($raw);
            });
        }
    }

    public function testBothFlagFormsSelectNamedOrFullModeWithoutCgroupProjection(): void
    {
        foreach ([['fixtur01'], ['fixtur01', '1024', '200']] as $args) {
            foreach ([['--lighttpd-min-threads=0'], ['--lighttpd-min-threads', '18']] as $option) {
                $parsed = \pmssParseCliTokens(array_merge(['userConfig.php'], $args, $option), ['lighttpd-min-threads']);
                $floor = \pmssUserConfigParseLighttpdMinThreadsOption(\pmssCliOption($parsed, 'lighttpd-min-threads'));
                $this->assertSame($args, $parsed['arguments']);
                $this->assertSame(count($args) === 1 ? 'named' : 'full',
                    \pmssUserConfigInvocationMode(array_merge([''], $parsed['arguments']), true, $floor !== null));
                $this->assertSame([], \pmssUserConfigCliResources($parsed, [], 'userConfigIndex', 'explicit'));
            }
        }
    }

    public function testMalformedCliValuesFailBeforeAccountLookup(): void
    {
        // These commands stop at validation; no real account or service is used.
        foreach ([['--lighttpd-min-threads'], ['--lighttpd-min-threads='], ['--lighttpd-min-threads', '-1'],
            ['--lighttpd-min-threads=1.5'], ['--lighttpd-min-threads=99999999999999999999999']] as $option) {
            $capture = $this->pmssRunRepoPhpScriptCommandWithTempStderr('scripts/util/userConfig.php',
                array_merge(['fixtur01'], $option));
            $this->pmssAssertCommandFailsToStderr($capture['result'], $capture['stderrPath'],
                "Invalid --lighttpd-min-threads value; expected a non-negative integer\n");
        }
    }

    public function testDurableMergePreservesOtherSettingsAndPlannerBounds(): void
    {
        $store = new \UserConfigStore($this->pmssMakeTempDir('pmss-user-worker-floor-'));
        $username = 'fixtur01';
        $this->assertTrue($store->set($username, ['ramMiB' => 512, 'rtorrentPort' => 5000,
            'quota' => 10, 'quotaBurst' => 12, 'gpuEnabled' => false, 'customFixture' => 'preserved']));
        foreach (['13' => 18, '48' => 48, '999' => 48, '0' => 6] as $raw => $threads) {
            $payload = $store->get($username);
            $payload['lighttpdMinThreads'] = \pmssUserConfigParseLighttpdMinThreadsOption((string) $raw);
            $this->assertTrue($store->set($username, $payload));
            $loaded = \pmssLighttpdUserConfigLoad($username, $store);
            $this->pmssAssertArraySubsetSame(['ramMiB' => 512, 'quota' => 10, 'gpuEnabled' => false,
                'customFixture' => 'preserved', 'lighttpdMinThreads' => (int) $raw], $loaded);
            $this->assertSame($threads, \pmssLighttpdResourcePlan([], ['cpuQuotaPercent' => 100], $loaded)['totalThreads']);
            // An unrelated reconfigure keeps the stored floor when this option is omitted.
            $rebuilt = \pmssUserConfigPayloadBuild($store, $loaded,
                ['name' => $username, 'memory' => 512, 'quota' => 10], [], null);
            $this->assertSame((int) $raw, $rebuilt['lighttpdMinThreads']);
        }
    }

    public function testCliPersistsBeforeStrictTargetedRefreshAndRetriesExplicitValues(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/lib/user/userConfigPrepare.php', [
            "'lighttpd-min-threads'", 'pmssUserConfigParseLighttpdMinThreadsOption',
            '|| $lighttpdMinThreads !== null', "pmssPasswdEntryLookup(\$user['name'])",
            "if (\$lighttpdMinThreads !== null) \$payload['lighttpdMinThreads'] = \$lighttpdMinThreads;",
            "if (!\$store->persist(\$user['name'], \$payload))", 'pmssCliExitWithStderr("Error: failed to persist user config',
            "if (\$lighttpdMinThreads !== null\n        && runStep('Configuring lighttpd worker floor'",
            "pmssBuildCommand('/scripts/util/userConfigLighttpd.php', ['--strict', \$user['name']])",
            'pmssCliExitWithStderr("Error: lighttpd config refresh failed; worker floor saved',
        ]);
        $this->assertStringContainsAllStrings(['--lighttpd-min-threads=THREADS', 'capped at 48',
            '0 restores CPU-derived sizing'], \pmssUserConfigCliUsage());
    }
}
