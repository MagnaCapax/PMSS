<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/userConfigCli.php';

class userConfigCliCharacterizationTest extends TestCase
{
    private function userConfigCommandArgs(array $positionals = []): array
    {
        return array_merge(['userConfig.php', 'alice', '512', '100'], $positionals);
    }

    /** @return array{0:array{options:array,arguments:array},1:array<int,string>} */
    private function parsedInvocation(array $arguments, array $options = []): array
    {
        $parsed = ['options' => $options, 'arguments' => $arguments];
        return [$parsed, array_merge([''], $arguments)];
    }

    public function testSparseUserConfigPositionalsRoundTripThroughSharedMap(): void
    {
        $positionals = \pmssUserConfigCliBuildUserConfigPositionals([
            'CPUWeight' => 200,
            'IOReadBW' => '/dev/sda:5M',
            'cpuQuotaPercent' => '150',
        ]);
        $this->assertSame(['', '200', '', '/dev/sda:5M', '', '', '', '150'], $positionals);

        $parsed = \pmssUserConfigCliResolvedResources(
            ['options' => []],
            $this->userConfigCommandArgs($positionals),
            'addUserOption',
            'userConfigIndex'
        );

        $this->assertSame(0, $parsed['trafficLimit']);
        $this->assertSame(200, $parsed['CPUWeight']);
        $this->assertSame(0, $parsed['IOWeight']);
        $this->assertSame('/dev/sda:5M', $parsed['IOReadBW']);
        $this->assertSame('150', $parsed['cpuQuotaPercent']);
        $this->assertSame(0, $parsed['trafficCapMbit']);
    }

    public function testResolvedResourcesPreferNamedOptionsOverPositionals(): void
    {
        [$parsed, $args] = $this->parsedInvocation(
            ['alice', '512', '100', '', '200', '', '/dev/sda:5M'],
            [
                'cpu-weight' => '400',
                'io-read-bw' => '/dev/nvme0n1:8M',
            ]
        );

        $resolved = \pmssUserConfigCliResolvedResources($parsed, $args, 'addUserOption', 'userConfigIndex');

        $this->assertSame(400, $resolved['CPUWeight']);
        $this->assertSame('/dev/nvme0n1:8M', $resolved['IOReadBW']);
    }

    public function testExplicitResourcesOnlyReturnProvidedValues(): void
    {
        [$parsed, $args] = $this->parsedInvocation(
            ['alice'],
            [
                'io-weight' => '300',
                'cpu-weight' => '',
                'io-latency-ms' => '50',
                'traffic-cap-mbit' => '0',
            ]
        );

        $resolved = \pmssUserConfigCliExplicitResources($parsed, $args, 'addUserOption', 'userConfigIndex');

        $this->assertSame([
            'trafficCapMbit' => 0,
            'IOWeight' => 300,
            'ioLatencyMs' => 50,
        ], $resolved);
    }

    public function testBuildUserConfigPositionalsKeepsExplicitZeroValues(): void
    {
        $positionals = \pmssUserConfigCliBuildUserConfigPositionals([
            'trafficCapMbit' => 0,
        ]);

        $this->assertSame(['', '', '', '', '', '', '', '', '0'], $positionals);
    }

    public function testExplicitResourcesDrivePersistedResourceUpdates(): void
    {
        [$parsed, $args] = $this->parsedInvocation([
            'alice',
            '512',
            '100',
            '900',
            '200',
            '',
            '',
            '',
            '',
            '',
            '',
            '0',
            '45',
            'enable=1 ctrl=user',
        ]);
        $explicit = \pmssUserConfigCliExplicitResources($parsed, $args, 'addUserOption', 'userConfigIndex');
        $presence = array_fill_keys(array_keys($explicit), true);

        $this->assertSame([
            'trafficLimit',
            'trafficCapMbit',
            'CPUWeight',
            'ioLatencyMs',
            'ioCostQos',
        ], array_keys($explicit));

        $payload = \pmssUserConfigCliApplyPersistedResources(
            [
                'CPUWeight' => 100,
                'trafficLimit' => 0,
            ],
            [
                'CPUWeight' => 200,
                'trafficLimit' => 900,
                'trafficCapMbit' => 12,
                'ioLatencyMs' => 45,
                'ioCostQos' => 'enable=1 ctrl=user',
            ],
            $presence
        );

        $this->assertSame(200, $payload['CPUWeight']);
        $this->assertSame(12, $payload['trafficCapMbit']);
        $this->assertSame(45, $payload['ioLatencyMs']);
        $this->assertSame('enable=1 ctrl=user', $payload['ioCostQos']);
        $this->assertSame(0, $payload['trafficLimit']);
    }

    public function testPersistedStoredResourcesMirrorSharedPersistedKeys(): void
    {
        $values = \pmssUserConfigCliPersistedStoredResources([
            'CPUWeight' => 220,
            'IOWeight' => 330,
            'trafficCapMbit' => 12,
            'trafficLimit' => 500,
            'ioLatencyMs' => 50,
            'ioCostQos' => 'enable=1 ctrl=user',
            'ioCostModel' => 'ctrl=user model=linear',
        ]);

        $this->assertSame([
            'trafficCapMbit' => 12,
            'CPUWeight' => 220,
            'IOWeight' => 330,
            'ioLatencyMs' => 50,
            'ioCostQos' => 'enable=1 ctrl=user',
            'ioCostModel' => 'ctrl=user model=linear',
        ], $values);
    }

    public function testCgroupResourceArgsUseSharedFlagMapOnce(): void
    {
        $args = \pmssUserConfigCliBuildCgroupResourceArgs([
            'trafficLimit' => 500,
            'CPUWeight' => 200,
            'IOWeight' => 300,
            'IOWriteBW' => '/dev/sda:7M',
            'ioLatencyMs' => 50,
            'ioCostQos' => 'enable=1 ctrl=user',
            'ioCostModel' => 'ctrl=user model=linear',
            'cpuQuotaPercent' => '150',
        ]);

        $this->assertSame([
            '--cpu-weight=200',
            '--io-weight=300',
            '--io-write-bw=/dev/sda:7M',
            '--io-latency-ms=50',
            '--io-cost-qos=enable=1 ctrl=user',
            '--io-cost-model=ctrl=user model=linear',
        ], $args);
    }
}
