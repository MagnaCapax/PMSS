<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/showTraffic.php';

class ShowTrafficFormatTest extends TestCase
{
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
                'required' => ["pmssListManagedUsersResult(\$listUsersScript)", 'pmssShowTrafficRawCounters($data)', "pmssShowTrafficDisplayAmounts(\$row['rawMiB'])"],
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
            $this->assertEquals($case[1], \pmssShowTrafficFormatRateDisplay($case[0]));
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
        ], \pmssShowTrafficJsonPayload([$row], 1536.25, 512.0, ['alice' => true, 'bob' => true], ['alice' => true], 0, 1, ['ghost']));
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
        ], \pmssShowTrafficJsonPayload($report['rows'], $report['dataMonthTotal'], $report['dataMonthTotalLocal'], $report['baseUsers'], $report['baseUsersWithStats'], $report['overLimitCount'], $report['nearLimitCount'], $report['missingStats']));
    }
}
