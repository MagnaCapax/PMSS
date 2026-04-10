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

    public function testPersistedPresenceKeepsExplicitZeroTrafficCap(): void
    {
        $presence = \pmssUserConfigCliPersistedPositionalPresence([
            'userConfig.php',
            'alice',
            '512',
            '100',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '0',
        ]);

        $this->assertTrue($presence['trafficCapMbit']);
        $this->assertFalse($presence['CPUWeight']);
    }

    public function testApplyPersistedResourcesUsesSharedPersistedKeyList(): void
    {
        $payload = \pmssUserConfigCliApplyPersistedResources(
            [
                'CPUWeight' => 100,
                'trafficLimit' => 0,
            ],
            [
                'CPUWeight' => 200,
                'trafficLimit' => 900,
                'trafficCapMbit' => 12,
            ],
            [
                'CPUWeight' => true,
                'trafficCapMbit' => true,
                'trafficLimit' => true,
            ]
        );

        $this->assertSame(200, $payload['CPUWeight']);
        $this->assertSame(12, $payload['trafficCapMbit']);
        $this->assertSame(0, $payload['trafficLimit']);
    }

    public function testClearWelcomeMessageRemovesLegacyBannerKey(): void
    {
        $withoutBanner = \pmssUserConfigClearWelcomeMessage([
            'quota' => 100,
            'welcomeMessage' => '<b>Hello</b>',
        ]);

        $this->assertFalse(array_key_exists('welcomeMessage', $withoutBanner));
        $this->assertSame(100, $withoutBanner['quota']);
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
