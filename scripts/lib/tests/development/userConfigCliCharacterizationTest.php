<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/userConfigCli.php';

class userConfigCliCharacterizationTest extends TestCase
{
    public function testSparseUserConfigPositionalsRoundTripThroughSharedMap(): void
    {
        $positionals = \pmssUserConfigCliBuildUserConfigPositionals([
            'CPUWeight' => 200,
            'IOReadBW' => '/dev/sda:5M',
            'cpuQuotaPercent' => '150',
        ]);
        $this->assertSame(['', '200', '', '/dev/sda:5M', '', '', '', '150'], $positionals);

        $parsed = \pmssUserConfigCliPositionalResources(
            array_merge(['userConfig.php', 'alice', '512', '100'], $positionals),
            'userConfigIndex'
        );

        $this->assertSame(0, $parsed['trafficLimit']);
        $this->assertSame(200, $parsed['CPUWeight']);
        $this->assertSame(0, $parsed['IOWeight']);
        $this->assertSame('/dev/sda:5M', $parsed['IOReadBW']);
        $this->assertSame('150', $parsed['cpuQuotaPercent']);
        $this->assertSame(0, $parsed['trafficCapMbit']);
    }

    public function testPersistedPresenceSkipsTransientTrafficLimit(): void
    {
        $presence = \pmssUserConfigCliPersistedPositionalPresence([
            'userConfig.php',
            'alice',
            '512',
            '100',
            '',
            '200',
            '',
            '/dev/sda:5M',
            '',
            '',
            '',
            '150',
        ]);

        $this->assertFalse(array_key_exists('trafficLimit', $presence));
        $this->assertTrue($presence['CPUWeight']);
        $this->assertFalse($presence['IOWeight']);
        $this->assertTrue($presence['IOReadBW']);
        $this->assertTrue($presence['cpuQuotaPercent']);
        $this->assertFalse($presence['trafficCapMbit']);
    }

    public function testBuildUserConfigPositionalsKeepsExplicitZeroValues(): void
    {
        $positionals = \pmssUserConfigCliBuildUserConfigPositionals([
            'trafficCapMbit' => 0,
        ]);

        $this->assertSame(['', '', '', '', '', '', '', '', '0'], $positionals);
    }

    public function testCgroupResourceArgsUseSharedFlagMapOnce(): void
    {
        $args = \pmssUserConfigCliBuildCgroupResourceArgs([
            'trafficLimit' => 500,
            'CPUWeight' => 200,
            'IOWeight' => 300,
            'IOWriteBW' => '/dev/sda:7M',
            'cpuQuotaPercent' => '150',
        ]);

        $this->assertSame([
            '--cpu-weight=200',
            '--io-weight=300',
            '--io-write-bw=/dev/sda:7M',
        ], $args);
    }
}
