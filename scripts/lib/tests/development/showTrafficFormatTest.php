<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/showTraffic.php';

class ShowTrafficFormatTest extends TestCase
{
    public function testSortErrorsAndHelpPrecedenceKeepCliContract(): void
    {
        $help = $this->pmssRunRepoPhpScriptCommand('scripts/showTraffic.php', ['--help'])['output'];
        foreach ([
            [['--sort'], 2, 'Error: --sort expects a value.'],
            [['--sort='], 2, 'Error: --sort expects a value.'],
            [['--sort=bad'], 2, "Error: invalid --sort value: bad\n".$help],
            [['--help', '--sort'], 0, $help],
            [['--help', '--sort=bad'], 0, $help],
            [['--help', '--color', '--no-color'], 0, $help],
            [['--color', '--no-color'], 2, 'Error: --color and --no-color are mutually exclusive.'],
        ] as [$args, $rc, $output]) {
            $result = $this->pmssRunRepoPhpScriptCommand('scripts/showTraffic.php', $args);
            $this->pmssAssertArraySubsetSame(['rc' => $rc, 'output' => $output], $result);
        }
    }

    public function testFormatTrafficAmountCharacterizationAcrossUnits(): void
    {
        $twoTiBInMiB = 2 * 1024 * 1024;
        foreach ([
            [500, '500MiB'],
            [2000, '1.95GiB'],
            [$twoTiBInMiB, '2TiB'],
            [0, '0MiB'],
            [1024, '1024MiB'],
            [1025, '1GiB'],
            [1024 * 1024, '1024GiB'],
            [(1024 * 1024) + 1, '1TiB'],
        ] as $case) {
            $this->assertEquals($case[1], \pmssTrafficFormatAmount($case[0]));
        }
    }

    public function testCliHelpAndSourceContracts(): void
    {
        $this->pmssAssertRepoPhpScriptOutputContains('scripts/showTraffic.php', ['--help'], ['--json', '--show-missing', '--extended', '--sort', '--color', '--no-color'], [], '');
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/showTraffic.php',
            ["require_once __DIR__.'/lib/traffic/report.php';", "pmssRunCliEntrypointWithArgv(__FILE__, 'pmssShowTrafficMain');"],
            ["exec(escapeshellarg(__DIR__.'/listUsers.php')"]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/traffic/report.php',
            ["require_once __DIR__.'/reportModel.php';", "require_once __DIR__.'/reportDisplay.php';"]);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/traffic/reportModel.php',
            ['pmssListManagedUsersResult($listUsersScript)', 'pmssShowTrafficRawCounters($data)']);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/traffic/reportDisplay.php',
            ["pmssShowTrafficDisplayAmounts(\$row['rawMiB'])"],
            ['pmssReadSerializedArrayFile(', 'pmssTrafficLimitReadGiBFile(']);
    }

    public function testRawCountersNormalizeRequiredTrafficStats(): void
    {
        $this->assertEquals([
            'month' => 2048.0,
            'week' => 1024.0,
            'day' => 512.5,
            'hour' => 0.0,
            '15min' => 12.0,
        ], \pmssShowTrafficRawCounters([
            'raw' => [
                'month' => '2048',
                'week' => 1024,
                'day' => '512.5',
                'hour' => 0,
                '15min' => '12',
                'extra' => 'preserved in raw JSON payload only',
            ],
        ]));
    }

    public function testRawCountersRejectMalformedTrafficStats(): void
    {
        $complete = ['month' => 10, 'week' => 7, 'day' => 1, 'hour' => 1, '15min' => 1];
        foreach ([
            [],
            ['raw' => 'not-array'],
            ['raw' => array_replace($complete, ['month' => 0])],
            ['raw' => array_diff_key($complete, ['week' => true])],
            ['raw' => array_replace($complete, ['15min' => 'bad'])],
        ] as $payload) {
            $this->assertSame(null, \pmssShowTrafficRawCounters($payload));
        }
    }

    public function testRawCountersRejectNonFiniteValuesInEveryWindow(): void
    {
        $raw = ['month' => 10, 'week' => 7, 'day' => 1, 'hour' => 1, '15min' => 1];
        foreach (array_keys($raw) as $key) {
            foreach ([INF, -INF, NAN, '1e309', '-1e309'] as $value) {
                $this->assertSame(null, \pmssShowTrafficRawCounters(['raw' => array_replace($raw, [$key => $value])]));
            }
            // Keep finite signed values and numeric spellings; this is not a new range policy.
            foreach ([PHP_FLOAT_MAX, -PHP_FLOAT_MAX, '-2.5', '1e3', ' 12'] as $value) {
                $expected = array_map('floatval', array_replace($raw, [$key => $value]));
                $this->assertSame($expected, \pmssShowTrafficRawCounters(['raw' => array_replace($raw, [$key => $value])]));
            }
        }
    }

    public function testReportSkipsNonFiniteCachedRowsAndKeepsValidRawPayload(): void
    {
        $root = $this->pmssMakeTempDir('pmss-traffic-finite-');
        $this->pmssTrackEnvOverrides(['PMSS_HOME_DIR' => $root]);
        $raw = ['week' => '700', 'month' => '900.125', 'day' => 144, 'hour' => '36', '15min' => 15, 'extra' => 'kept'];
        $this->pmssWriteSerializedFixture($root.'/fixtur90', ['raw' => $raw]);
        $users = ['fixtur90', 'fixtur97'];
        foreach (['month' => INF, 'week' => -INF, 'day' => NAN, 'hour' => '1e309', '15min' => '-1e309'] as $key => $value) {
            $user = 'fixtur8'.count($users);
            $users[] = $user;
            $this->pmssWriteSerializedFixture($root.'/'.$user, ['raw' => array_replace($raw, [$key => $value])]);
        }

        $report = \pmssShowTrafficReportBuild($users, $root);
        $payload = \pmssShowTrafficJsonPayload($report);
        $this->assertSame(['fixtur90'], array_column($report['rows'], 'user'));
        $this->assertSame($raw, $payload['users'][0]['rawMiB']);
        $this->assertSame(900.125, $report['totals']['monthMiB']);
        $this->assertSame(['fixtur97'], $payload['missingStatsUsers']);
        $this->assertSame(['totalUsers' => 7, 'usersWithStats' => 1, 'overLimit' => 0, 'nearLimit' => 0, 'missingStats' => 1], $report['summary']);
        $this->assertTrue(is_string(json_encode($payload)));
    }

    public function testFormatRateDisplay(): void
    {
        foreach ([
            [0.0, '0.00MiB/s'],
            [12.345, '12.35MiB/s'],
            [999.99, '999.99MiB/s'],
            [1000.0, '0.98GiB/s'],
            [1024.0, '1.00GiB/s'],
            [2048.0, '2.00GiB/s'],
        ] as $case) {
            $rates = array_fill_keys(['week', 'day', 'hour', '15min'], $case[0]);
            $this->assertSame(array_fill(0, 4, $case[1]), \pmssShowTrafficRateColumns($rates, true));
            $this->assertSame(array_fill(0, 4, sprintf('%.2f', $case[0])), \pmssShowTrafficRateColumns($rates, false));
        }
    }

    public function testDisplayAmountsDeriveOutputFromCanonicalRawCounters(): void
    {
        $rawMiB = ['month' => 1025.0, 'week' => 512.0, 'day' => 2.0, 'hour' => 1.0, '15min' => 0.5];
        $this->assertEquals(['month' => '1GiB', 'week' => '512MiB', 'day' => '2MiB'], \pmssShowTrafficDisplayAmounts($rawMiB));
    }

    public function testJsonPayloadBuilderMatchesSnapshot(): void
    {
        $row = [
            'user' => 'fixtur91', 'rates' => ['week' => 1.25, 'day' => 2.5, 'hour' => 3.75, '15min' => 4.0],
            'inboundMonthMiB' => 256.5, 'inboundRatio' => 0.25, 'limitMiB' => 2048.0, 'pctUsed' => 87.654, 'overLimit' => false, 'nearLimit' => true,
            'rawMiB' => ['month' => 1024.0, 'week' => 2.0, 'day' => 3.0, 'hour' => 4.0, '15min' => 5.0],
        ];

        $this->assertEquals([
            'users' => [[
                'user' => 'fixtur91', 'display' => ['month' => '1024MiB', 'week' => '2MiB', 'day' => '3MiB'], 'rates' => ['week' => 1.25, 'day' => 2.5, 'hour' => 3.75, '15min' => 4.0],
                'inboundMonthMiB' => 256.5, 'inboundOutboundRatio' => 0.25, 'limitMiB' => 2048.0, 'pctUsed' => 87.65, 'overLimit' => false, 'nearLimit' => true,
                'rawMiB' => ['month' => 1024.0, 'week' => 2.0, 'day' => 3.0, 'hour' => 4.0, '15min' => 5.0],
            ]],
            'totals' => ['monthMiB' => 1536.25, 'monthLocalMiB' => 512.0, 'monthTiB' => 0.0, 'monthLocalTiB' => 0.0],
            'summary' => ['totalUsers' => 2, 'usersWithStats' => 1, 'overLimit' => 0, 'nearLimit' => 1, 'missingStats' => 1],
            'missingStatsUsers' => ['fixtur98'],
        ], \pmssShowTrafficJsonPayload([
            'rows' => [$row], 'totals' => ['monthMiB' => 1536.25, 'monthLocalMiB' => 512.0, 'monthTiB' => 1536.25 / 1024 / 1024, 'monthLocalTiB' => 512.0 / 1024 / 1024],
            'summary' => ['totalUsers' => 2, 'usersWithStats' => 1, 'overLimit' => 0, 'nearLimit' => 1, 'missingStats' => 1], 'missingStats' => ['fixtur98'],
        ]));
    }

    public function testReportBuilderSnapshotCoversTotalsLimitsAndSorting(): void
    {
        $root = $this->pmssMakeTempDir('pmss-show-traffic-report-');
        $statsDir = $root.'/runtime/trafficStats';
        $homeDir = $root.'/home';
        $this->pmssEnsureDir($homeDir.'/fixtur91');
        // The limit comes from the root runtime file; the home copy is display only (ADR 0213).
        $this->pmssWriteFile($root.'/seedbox/trafficLimits/fixtur91', "1\n");
        $this->pmssWriteFile($homeDir.'/fixtur92/.trafficLimit', "1\n");
        $this->pmssTrackEnvOverrides(['PMSS_HOME_DIR' => $homeDir, 'PMSS_RUNTIME_DIR' => $root.'/seedbox']);

        $payload = static function (float $month): array {
            return ['raw' => ['month' => $month, 'week' => 700.0, 'day' => 144.0, 'hour' => 36.0, '15min' => 15.0], 'daily' => []];
        };
        $this->pmssWriteSerializedFixture($statsDir.'/fixtur91', $payload(900.0));
        $this->pmssWriteSerializedFixture($statsDir.'/fixtur91-localnet', $payload(50.0));
        $this->pmssWriteSerializedFixture($statsDir.'/fixtur92', $payload(500.0));

        $report = \pmssShowTrafficReportBuild(['fixtur92', 'fixtur91-localnet', 'fixtur98', 'fixtur91'], $statsDir);
        \pmssShowTrafficRowsSort($report['rows'], 'pct');

        $this->assertEquals([
            'users' => [
                ['user' => 'fixtur91', 'display' => ['month' => '900MiB', 'week' => '700MiB', 'day' => '144MiB'], 'rates' => ['week' => 0.0, 'day' => 0.0, 'hour' => 0.01, '15min' => 0.02], 'inboundMonthMiB' => null, 'inboundOutboundRatio' => null, 'limitMiB' => 1024, 'pctUsed' => 87.89, 'overLimit' => false, 'nearLimit' => true, 'rawMiB' => ['month' => 900.0, 'week' => 700.0, 'day' => 144.0, 'hour' => 36.0, '15min' => 15.0]],
                ['user' => 'fixtur91-localnet', 'display' => ['month' => '50MiB', 'week' => '700MiB', 'day' => '144MiB'], 'rates' => ['week' => 0.0, 'day' => 0.0, 'hour' => 0.01, '15min' => 0.02], 'inboundMonthMiB' => null, 'inboundOutboundRatio' => null, 'limitMiB' => 1024, 'pctUsed' => 4.88, 'overLimit' => false, 'nearLimit' => false, 'rawMiB' => ['month' => 50.0, 'week' => 700.0, 'day' => 144.0, 'hour' => 36.0, '15min' => 15.0]],
                ['user' => 'fixtur92', 'display' => ['month' => '500MiB', 'week' => '700MiB', 'day' => '144MiB'], 'rates' => ['week' => 0.0, 'day' => 0.0, 'hour' => 0.01, '15min' => 0.02], 'inboundMonthMiB' => null, 'inboundOutboundRatio' => null, 'limitMiB' => null, 'pctUsed' => null, 'overLimit' => false, 'nearLimit' => false, 'rawMiB' => ['month' => 500.0, 'week' => 700.0, 'day' => 144.0, 'hour' => 36.0, '15min' => 15.0]],
            ],
            'totals' => ['monthMiB' => 1450.0, 'monthLocalMiB' => 50.0, 'monthTiB' => 0.0, 'monthLocalTiB' => 0.0],
            'summary' => ['totalUsers' => 3, 'usersWithStats' => 2, 'overLimit' => 0, 'nearLimit' => 1, 'missingStats' => 1],
            'missingStatsUsers' => ['fixtur98'],
        ], \pmssShowTrafficJsonPayload($report));
        $this->pmssAssertArraySubsetSame(['totalUsers' => 3, 'usersWithStats' => 2], $report['summary']);
        $duplicateReport = \pmssShowTrafficReportBuild(['fixtur91', 'fixtur91'], $statsDir);
        $this->assertSame(1, \pmssShowTrafficJsonPayload($duplicateReport)['summary']['usersWithStats']);
    }

    public function testTextSummaryMatchesExistingLayouts(): void
    {
        $report = [
            'missingStats' => ['fixtur98'], 'totals' => ['monthTiB' => 1.0, 'monthLocalTiB' => 0.5],
            'summary' => ['totalUsers' => 2, 'overLimit' => 1, 'nearLimit' => 0],
        ];
        [, $plain] = $this->pmssCaptureStdout(static function () use ($report): void {
            \pmssShowTrafficPrintSummary($report, false, true);
        });
        $this->assertSame("* Month Total: 1.00TiB - Local Total: 0.50TiB\n* Missing traffic stats for 1 users (run trafficStats to rebuild).\n* Missing: fixtur98\n", $plain);
        [, $extended] = $this->pmssCaptureStdout(static function () use ($report): void {
            \pmssShowTrafficPrintSummary($report, true, false);
        });
        $line = str_repeat('-', 72)."\n";
        $this->assertSame($line." Total users: 2  |  Over limit: 1  |  Near limit (>=80%): 0\n Month egress: 1.00TiB  |  Local: 0.50TiB\n Missing stats: 1 users (--show-missing to list)\n".$line, $extended);
    }

    public function testReportSummaryCharacterizesDuplicatesLocalOnlyAndRejectedPayloads(): void
    {
        $root = $this->pmssMakeTempDir('pmss-traffic-summary-lock-');
        $statsDir = $root.'/stats';
        $this->pmssEnsureDir($root.'/fixtur01');
        $this->pmssWriteFile($root.'/seedbox/trafficLimits/fixtur01', "1\n");
        $this->pmssTrackEnvOverrides(['PMSS_HOME_DIR' => $root, 'PMSS_RUNTIME_DIR' => $root.'/seedbox']);
        // Preserve raw numeric strings, extra fields, and their original insertion order.
        $raw = ['week' => '700', 'month' => '900.125', 'day' => 144, 'hour' => '36', '15min' => 15, 'extra' => 'kept'];
        $this->pmssWriteSerializedFixture($statsDir.'/fixtur01', ['raw' => $raw]);
        $this->pmssWriteSerializedFixture($statsDir.'/fixtur99-localnet', ['raw' => array_replace($raw, ['month' => '100.25'])]);
        $this->pmssWriteFile($statsDir.'/fixtur94', 'invalid serialized payload');
        $this->pmssWriteSerializedFixture($statsDir.'/fixtur95', ['raw' => array_replace($raw, ['month' => 0])]);
        $this->pmssWriteSerializedFixture($statsDir.'/fixtur96', ['raw' => array_replace($raw, ['hour' => 'bad'])]);
        $report = \pmssShowTrafficReportBuild(['fixtur99-localnet', 'fixtur94', 'fixtur95', 'fixtur96', 'fixtur01', 'fixtur01', 'fixtur97', 'fixtur99-localnet'], $statsDir);
        $payload = \pmssShowTrafficJsonPayload($report);
        $this->pmssAssertArraySubsetSame([
            'totals' => ['monthMiB' => 2000.75, 'monthLocalMiB' => 200.5, 'monthTiB' => 0.0, 'monthLocalTiB' => 0.0],
            'summary' => ['totalUsers' => 6, 'usersWithStats' => 1, 'overLimit' => 0, 'nearLimit' => 2, 'missingStats' => 1],
            'missingStatsUsers' => ['fixtur97'],
        ], $payload);
        $this->pmssAssertArraySubsetSame([
            'rawMiB' => $raw, 'display' => ['week' => '700MiB', 'month' => '900.13MiB', 'day' => '144MiB'],
            'pctUsed' => 87.9,
        ], $payload['users'][1]);
        [, $plain] = $this->pmssCaptureStdout(static function () use ($report): void {
            \pmssShowTrafficPrintSummary($report, false, true);
        });
        $this->assertSame("* Month Total: 0.00TiB - Local Total: 0.00TiB\n* Missing traffic stats for 1 users (run trafficStats to rebuild).\n* Missing: fixtur97\n", $plain);
    }

    public function testReportSummationPrecedesSortingAndKeepsEmptyFloatTotals(): void
    {
        $root = $this->pmssMakeTempDir('pmss-traffic-total-order-');
        $this->pmssTrackEnvOverrides(['PMSS_HOME_DIR' => $root]);
        $empty = \pmssShowTrafficJsonPayload(\pmssShowTrafficReportBuild([], $root));
        $this->pmssAssertArraySubsetSame([
            'totals' => ['monthMiB' => 0.0, 'monthLocalMiB' => 0.0, 'monthTiB' => 0.0, 'monthLocalTiB' => 0.0],
            'summary' => ['totalUsers' => 0, 'usersWithStats' => 0, 'overLimit' => 0, 'nearLimit' => 0, 'missingStats' => 0],
        ], $empty);
        foreach (['fixtur01' => 1.0e16, 'fixtur02' => -1.0e16, 'fixtur03' => 1.0] as $user => $month) {
            $this->pmssWriteSerializedFixture($root.'/'.$user, ['raw' => ['month' => $month, 'week' => 1, 'day' => 1, 'hour' => 1, '15min' => 1]]);
        }
        $report = \pmssShowTrafficReportBuild(['fixtur01', 'fixtur02', 'fixtur03'], $root);
        \pmssShowTrafficRowsSort($report['rows'], 'month');
        $this->assertSame(['fixtur01', 'fixtur03', 'fixtur02'], array_column($report['rows'], 'user'));
        $this->assertSame(1.0, \pmssShowTrafficJsonPayload($report)['totals']['monthMiB']);
    }
}
