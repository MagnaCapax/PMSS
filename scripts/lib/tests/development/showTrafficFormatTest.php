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

    public function testHelpIncludesJsonOption(): void
    {
        $out = $this->pmssRunRepoPhpScript('scripts/showTraffic.php', ['--help'], [], '');

        $this->assertTrue(is_string($out));
        $this->assertStringContainsAllStrings(['--json', '--show-missing', '--extended', '--sort', '--color', '--no-color'], $out);
    }

    public function testShowTrafficUsesSharedManagedUsersParser(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/showTraffic.php', ["pmssListManagedUsersResult(__DIR__.'/listUsers.php')", 'pmssShowTrafficRawCounters($data)', "array_map('pmssTrafficFormatAmount', \$rawCounters)"]);
        $this->pmssAssertRepoFileNotContainsString('scripts/showTraffic.php', "exec(escapeshellarg(__DIR__.'/listUsers.php')");
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

    public function testJsonPayloadBuilderMatchesSnapshot(): void
    {
        $row = [
            'user' => 'alice', 'display' => ['month' => '1GiB', 'week' => '2MiB', 'day' => '3MiB'], 'rates' => ['week' => 1.25, 'day' => 2.5, 'hour' => 3.75, '15min' => 4.0],
            'inboundMonthMiB' => 256.5, 'inboundRatio' => 0.25, 'limitMiB' => 2048.0, 'pctUsed' => 87.654, 'overLimit' => false, 'nearLimit' => true,
            'rawMiB' => ['month' => 1024.0, 'week' => 2.0, 'day' => 3.0, 'hour' => 4.0, '15min' => 5.0],
        ];

        $this->assertEquals([
            'users' => [[
                'user' => 'alice', 'display' => ['month' => '1GiB', 'week' => '2MiB', 'day' => '3MiB'], 'rates' => ['week' => 1.25, 'day' => 2.5, 'hour' => 3.75, '15min' => 4.0],
                'inboundMonthMiB' => 256.5, 'inboundOutboundRatio' => 0.25, 'limitMiB' => 2048.0, 'pctUsed' => 87.65, 'overLimit' => false, 'nearLimit' => true,
                'rawMiB' => ['month' => 1024.0, 'week' => 2.0, 'day' => 3.0, 'hour' => 4.0, '15min' => 5.0],
            ]],
            'totals' => ['monthMiB' => 1536.25, 'monthLocalMiB' => 512.0, 'monthTiB' => 0.0, 'monthLocalTiB' => 0.0],
            'summary' => ['totalUsers' => 2, 'usersWithStats' => 1, 'overLimit' => 0, 'nearLimit' => 1, 'missingStats' => 1],
            'missingStatsUsers' => ['ghost'],
        ], \pmssShowTrafficJsonPayload([$row], 1536.25, 512.0, ['alice' => true, 'bob' => true], ['alice' => true], 0, 1, ['ghost']));
    }
}
