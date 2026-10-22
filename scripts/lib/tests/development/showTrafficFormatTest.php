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
            $this->assertSame($rc, $result['rc']);
            $this->assertSame($output, $result['output']);
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
        $this->pmssAssertRepoFileContractCases([
            'scripts/showTraffic.php' => [
                'required' => ["require_once __DIR__.'/lib/traffic/report.php';", "pmssRunCliEntrypointWithArgv(__FILE__, 'pmssShowTrafficMain');"],
                'forbidden' => ["exec(escapeshellarg(__DIR__.'/listUsers.php')"],
            ],
            'scripts/lib/traffic/report.php' => [
                'required' => ["require_once __DIR__.'/reportModel.php';", "require_once __DIR__.'/reportDisplay.php';"],
            ],
            'scripts/lib/traffic/reportModel.php' => [
                'required' => ['pmssListManagedUsersResult($listUsersScript)', 'pmssShowTrafficRawCounters($data)'],
            ],
            'scripts/lib/traffic/reportDisplay.php' => [
                'required' => ["pmssShowTrafficDisplayAmounts(\$row['rawMiB'])"],
                'forbidden' => ['pmssReadSerializedArrayFile(', 'pmssTrafficLimitReadGiBFile('],
            ],
        ]);
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
            'user' => 'alice', 'rates' => ['week' => 1.25, 'day' => 2.5, 'hour' => 3.75, '15min' => 4.0],
            'inboundMonthMiB' => 256.5, 'inboundRatio' => 0.25, 'limitMiB' => 2048.0, 'pctUsed' => 87.654, 'overLimit' => false, 'nearLimit' => true,
            'rawMiB' => ['month' => 1024.0, 'week' => 2.0, 'day' => 3.0, 'hour' => 4.0, '15min' => 5.0],
        ];

        $this->assertEquals([
            'users' => [[
                'user' => 'alice', 'display' => ['month' => '1024MiB', 'week' => '2MiB', 'day' => '3MiB'], 'rates' => ['week' => 1.25, 'day' => 2.5, 'hour' => 3.75, '15min' => 4.0],
                'inboundMonthMiB' => 256.5, 'inboundOutboundRatio' => 0.25, 'limitMiB' => 2048.0, 'pctUsed' => 87.65, 'overLimit' => false, 'nearLimit' => true,
                'rawMiB' => ['month' => 1024.0, 'week' => 2.0, 'day' => 3.0, 'hour' => 4.0, '15min' => 5.0],
            ]],
            'totals' => ['monthMiB' => 1536.25, 'monthLocalMiB' => 512.0, 'monthTiB' => 0.0, 'monthLocalTiB' => 0.0],
            'summary' => ['totalUsers' => 2, 'usersWithStats' => 1, 'overLimit' => 0, 'nearLimit' => 1, 'missingStats' => 1],
            'missingStatsUsers' => ['ghost'],
        ], \pmssShowTrafficJsonPayload([
            'rows' => [$row], 'totals' => ['monthMiB' => 1536.25, 'monthLocalMiB' => 512.0, 'monthTiB' => 1536.25 / 1024 / 1024, 'monthLocalTiB' => 512.0 / 1024 / 1024],
            'summary' => ['totalUsers' => 2, 'usersWithStats' => 1, 'overLimit' => 0, 'nearLimit' => 1, 'missingStats' => 1], 'missingStats' => ['ghost'],
        ]));
    }

    public function testReportBuilderSnapshotCoversTotalsLimitsAndSorting(): void
    {
        $root = $this->pmssMakeTempDir('pmss-show-traffic-report-');
        $statsDir = $root.'/runtime/trafficStats';
        $homeDir = $root.'/home';
        @mkdir($statsDir, 0755, true);
        @mkdir($homeDir.'/alice', 0755, true);
        @mkdir($homeDir.'/bob', 0755, true);
        file_put_contents($homeDir.'/alice/.trafficLimit', "1\n");
        $this->pmssTrackEnvOverrides(['PMSS_HOME_DIR' => $homeDir]);

        $payload = static function (float $month): array {
            return ['raw' => ['month' => $month, 'week' => 700.0, 'day' => 144.0, 'hour' => 36.0, '15min' => 15.0], 'daily' => []];
        };
        file_put_contents($statsDir.'/alice', serialize($payload(900.0)));
        file_put_contents($statsDir.'/alice-localnet', serialize($payload(50.0)));
        file_put_contents($statsDir.'/bob', serialize($payload(500.0)));

        $report = \pmssShowTrafficReportBuild(['bob', 'alice-localnet', 'ghost', 'alice'], $statsDir);
        \pmssShowTrafficRowsSort($report['rows'], 'pct');

        $this->assertEquals([
            'users' => [
                ['user' => 'alice', 'display' => ['month' => '900MiB', 'week' => '700MiB', 'day' => '144MiB'], 'rates' => ['week' => 0.0, 'day' => 0.0, 'hour' => 0.01, '15min' => 0.02], 'inboundMonthMiB' => null, 'inboundOutboundRatio' => null, 'limitMiB' => 1024, 'pctUsed' => 87.89, 'overLimit' => false, 'nearLimit' => true, 'rawMiB' => ['month' => 900.0, 'week' => 700.0, 'day' => 144.0, 'hour' => 36.0, '15min' => 15.0]],
                ['user' => 'alice-localnet', 'display' => ['month' => '50MiB', 'week' => '700MiB', 'day' => '144MiB'], 'rates' => ['week' => 0.0, 'day' => 0.0, 'hour' => 0.01, '15min' => 0.02], 'inboundMonthMiB' => null, 'inboundOutboundRatio' => null, 'limitMiB' => 1024, 'pctUsed' => 4.88, 'overLimit' => false, 'nearLimit' => false, 'rawMiB' => ['month' => 50.0, 'week' => 700.0, 'day' => 144.0, 'hour' => 36.0, '15min' => 15.0]],
                ['user' => 'bob', 'display' => ['month' => '500MiB', 'week' => '700MiB', 'day' => '144MiB'], 'rates' => ['week' => 0.0, 'day' => 0.0, 'hour' => 0.01, '15min' => 0.02], 'inboundMonthMiB' => null, 'inboundOutboundRatio' => null, 'limitMiB' => null, 'pctUsed' => null, 'overLimit' => false, 'nearLimit' => false, 'rawMiB' => ['month' => 500.0, 'week' => 700.0, 'day' => 144.0, 'hour' => 36.0, '15min' => 15.0]],
            ],
            'totals' => ['monthMiB' => 1450.0, 'monthLocalMiB' => 50.0, 'monthTiB' => 0.0, 'monthLocalTiB' => 0.0],
            'summary' => ['totalUsers' => 3, 'usersWithStats' => 2, 'overLimit' => 0, 'nearLimit' => 1, 'missingStats' => 1],
            'missingStatsUsers' => ['ghost'],
        ], \pmssShowTrafficJsonPayload($report));
        $this->assertSame(3, $report['summary']['totalUsers']);
        $this->assertSame(2, $report['summary']['usersWithStats']);
        $duplicateReport = \pmssShowTrafficReportBuild(['alice', 'alice'], $statsDir);
        $this->assertSame(1, \pmssShowTrafficJsonPayload($duplicateReport)['summary']['usersWithStats']);
    }

    public function testTextSummaryMatchesExistingLayouts(): void
    {
        $report = [
            'missingStats' => ['ghost'], 'totals' => ['monthTiB' => 1.0, 'monthLocalTiB' => 0.5],
            'summary' => ['totalUsers' => 2, 'overLimit' => 1, 'nearLimit' => 0],
        ];
        [, $plain] = $this->pmssCaptureStdout(static function () use ($report): void {
            \pmssShowTrafficPrintSummary($report, false, true);
        });
        $this->assertSame("* Month Total: 1.00TiB - Local Total: 0.50TiB\n* Missing traffic stats for 1 users (run trafficStats to rebuild).\n* Missing: ghost\n", $plain);
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
        mkdir($statsDir);
        mkdir($root.'/alpha');
        file_put_contents($root.'/alpha/.trafficLimit', "1\n");
        $this->pmssTrackEnvOverrides(['PMSS_HOME_DIR' => $root]);
        // Preserve raw numeric strings, extra fields, and their original insertion order.
        $raw = ['week' => '700', 'month' => '900.125', 'day' => 144, 'hour' => '36', '15min' => 15, 'extra' => 'kept'];
        file_put_contents($statsDir.'/alpha', serialize(['raw' => $raw]));
        file_put_contents($statsDir.'/zed-localnet', serialize(['raw' => array_replace($raw, ['month' => '100.25'])]));
        file_put_contents($statsDir.'/broken', 'invalid serialized payload');
        file_put_contents($statsDir.'/zero', serialize(['raw' => array_replace($raw, ['month' => 0])]));
        file_put_contents($statsDir.'/bad', serialize(['raw' => array_replace($raw, ['hour' => 'bad'])]));
        $report = \pmssShowTrafficReportBuild(['zed-localnet', 'broken', 'zero', 'bad', 'alpha', 'alpha', 'absent', 'zed-localnet'], $statsDir);
        $payload = \pmssShowTrafficJsonPayload($report);
        $this->assertSame(['monthMiB' => 2000.75, 'monthLocalMiB' => 200.5, 'monthTiB' => 0.0, 'monthLocalTiB' => 0.0], $payload['totals']);
        $this->assertSame(['totalUsers' => 6, 'usersWithStats' => 1, 'overLimit' => 0, 'nearLimit' => 2, 'missingStats' => 1], $payload['summary']);
        $this->assertSame(['absent'], $payload['missingStatsUsers']);
        $this->assertSame($raw, $payload['users'][1]['rawMiB']);
        $this->assertSame(['week' => '700MiB', 'month' => '900.13MiB', 'day' => '144MiB'], $payload['users'][1]['display']);
        $this->assertSame(87.9, $payload['users'][1]['pctUsed']);
        [, $plain] = $this->pmssCaptureStdout(static function () use ($report): void {
            \pmssShowTrafficPrintSummary($report, false, true);
        });
        $this->assertSame("* Month Total: 0.00TiB - Local Total: 0.00TiB\n* Missing traffic stats for 1 users (run trafficStats to rebuild).\n* Missing: absent\n", $plain);
    }

    public function testReportSummationPrecedesSortingAndKeepsEmptyFloatTotals(): void
    {
        $root = $this->pmssMakeTempDir('pmss-traffic-total-order-');
        $this->pmssTrackEnvOverrides(['PMSS_HOME_DIR' => $root]);
        $empty = \pmssShowTrafficJsonPayload(\pmssShowTrafficReportBuild([], $root));
        $this->assertSame(['monthMiB' => 0.0, 'monthLocalMiB' => 0.0, 'monthTiB' => 0.0, 'monthLocalTiB' => 0.0], $empty['totals']);
        $this->assertSame(['totalUsers' => 0, 'usersWithStats' => 0, 'overLimit' => 0, 'nearLimit' => 0, 'missingStats' => 0], $empty['summary']);
        foreach (['alpha' => 1.0e16, 'beta' => -1.0e16, 'gamma' => 1.0] as $user => $month) {
            file_put_contents($root.'/'.$user, serialize(['raw' => ['month' => $month, 'week' => 1, 'day' => 1, 'hour' => 1, '15min' => 1]]));
        }
        $report = \pmssShowTrafficReportBuild(['alpha', 'beta', 'gamma'], $root);
        \pmssShowTrafficRowsSort($report['rows'], 'month');
        $this->assertSame(['alpha', 'gamma', 'beta'], array_column($report['rows'], 'user'));
        $this->assertSame(1.0, \pmssShowTrafficJsonPayload($report)['totals']['monthMiB']);
    }
}
