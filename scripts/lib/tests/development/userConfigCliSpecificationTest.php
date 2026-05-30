<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/userConfigCli.php';

class userConfigCliSpecificationTest extends TestCase
{
    /** @param array<string,mixed> $expected */
    private function assertSpecFieldValues(string $field, array $expected): void
    {
        $specs = \pmssUserConfigCliResourceSpecs();
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $specs[$key][$field]);
        }
    }

    public function testResourceSpecKeepsAddUserLegacyOrderingStable(): void
    {
        $this->assertSpecFieldValues('addUserLegacyIndex', [
            'trafficLimit' => 5,
            'trafficCapMbit' => 6,
            'CPUWeight' => 8,
            'cpuQuotaPercent' => 14,
            'ioLatencyMs' => 15,
            'ioCostQos' => 16,
            'ioCostModel' => 17,
        ]);
    }

    public function testResourceSpecKeepsUserConfigPositionalOrderingStable(): void
    {
        $this->assertSpecFieldValues('userConfigIndex', [
            'trafficLimit' => 4,
            'CPUWeight' => 5,
            'IOWriteIOPS' => 10,
            'trafficCapMbit' => 12,
            'ioLatencyMs' => 13,
            'ioCostQos' => 14,
            'ioCostModel' => 15,
        ]);
    }

    public function testResourceSpecRetainsHumanUsageStrings(): void
    {
        $this->assertSpecFieldValues('usage', [
            'trafficLimit' => '--traffic-limit-gb=GIB',
            'IOReadBW' => '--io-read-bw=/dev/DEVICE:RATE',
            'cpuQuotaPercent' => '--cpu-quota-percent=PERCENT|infinity',
            'ioLatencyMs' => '--io-latency-ms=MS',
            'ioCostQos' => '--io-cost-qos=SETTING',
            'ioCostModel' => '--io-cost-model=SETTING',
        ]);
    }

    public function testResourceSpecFlagsPersistOnlyStoredFields(): void
    {
        $this->assertSpecFieldValues('persist', [
            'trafficLimit' => false,
            'trafficCapMbit' => true,
            'CPUWeight' => true,
            'ioCostQos' => true,
            'ioCostModel' => true,
        ]);
    }

    public function testResourceSpecRetainsCgroupFlagMappings(): void
    {
        $specs = \pmssUserConfigCliResourceSpecs();

        $this->assertSpecFieldValues('cgroupFlag', [
            'CPUWeight' => '--cpu-weight=',
            'IOWriteBW' => '--io-write-bw=',
            'ioLatencyMs' => '--io-latency-ms=',
            'ioCostQos' => '--io-cost-qos=',
            'ioCostModel' => '--io-cost-model=',
        ]);
        $this->assertFalse(isset($specs['trafficLimit']['cgroupFlag']));
        $this->assertFalse(isset($specs['cpuQuotaPercent']['cgroupFlag']));
    }

    public function testResourceHelpGroupsKeepOutputOrderStable(): void
    {
        foreach ([
            'addUserPositionals' => ['trafficLimit', 'trafficCapMbit'],
            'addUserPrimaryOptions' => ['trafficLimit', 'iopsLimit', 'trafficCapMbit'],
            'userConfigPositionals' => ['trafficLimit', 'CPUWeight', 'IOWeight', 'IOReadBW', 'IOWriteBW', 'IOReadIOPS', 'IOWriteIOPS', 'cpuQuotaPercent', 'trafficCapMbit', 'ioLatencyMs', 'ioCostQos', 'ioCostModel'],
            'userConfigNamedOptions' => ['trafficLimit', 'iopsLimit', 'CPUWeight', 'IOWeight', 'IOReadBW', 'IOWriteBW', 'IOReadIOPS', 'IOWriteIOPS', 'cpuQuotaPercent', 'trafficCapMbit', 'ioLatencyMs', 'ioCostQos', 'ioCostModel'],
        ] as $group => $expected) {
            $this->assertSame($expected, \pmssUserConfigCliResourceGroupKeys($group));
        }
    }
}
