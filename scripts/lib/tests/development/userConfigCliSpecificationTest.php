<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/userConfigCli.php';

class userConfigCliSpecificationTest extends TestCase
{
    public function testResourceSpecKeepsAddUserLegacyOrderingStable(): void
    {
        $specs = \pmssUserConfigCliResourceSpecs();

        $this->assertSame(5, $specs['trafficLimit']['addUserLegacyIndex']);
        $this->assertSame(6, $specs['trafficCapMbit']['addUserLegacyIndex']);
        $this->assertSame(8, $specs['CPUWeight']['addUserLegacyIndex']);
        $this->assertSame(14, $specs['cpuQuotaPercent']['addUserLegacyIndex']);
        $this->assertSame(15, $specs['ioLatencyMs']['addUserLegacyIndex']);
    }

    public function testResourceSpecKeepsUserConfigPositionalOrderingStable(): void
    {
        $specs = \pmssUserConfigCliResourceSpecs();

        $this->assertSame(4, $specs['trafficLimit']['userConfigIndex']);
        $this->assertSame(5, $specs['CPUWeight']['userConfigIndex']);
        $this->assertSame(10, $specs['IOWriteIOPS']['userConfigIndex']);
        $this->assertSame(12, $specs['trafficCapMbit']['userConfigIndex']);
        $this->assertSame(13, $specs['ioLatencyMs']['userConfigIndex']);
    }

    public function testResourceSpecRetainsHumanUsageStrings(): void
    {
        $specs = \pmssUserConfigCliResourceSpecs();

        $this->assertSame('--traffic-limit-gb=GIB', $specs['trafficLimit']['usage']);
        $this->assertSame('--io-read-bw=/dev/DEVICE:RATE', $specs['IOReadBW']['usage']);
        $this->assertSame('--cpu-quota-percent=PERCENT|infinity', $specs['cpuQuotaPercent']['usage']);
        $this->assertSame('--io-latency-ms=MS', $specs['ioLatencyMs']['usage']);
    }

    public function testResourceSpecFlagsPersistOnlyStoredFields(): void
    {
        $specs = \pmssUserConfigCliResourceSpecs();

        $this->assertFalse($specs['trafficLimit']['persist']);
        $this->assertTrue($specs['trafficCapMbit']['persist']);
        $this->assertTrue($specs['CPUWeight']['persist']);
    }

    public function testResourceSpecRetainsCgroupFlagMappings(): void
    {
        $specs = \pmssUserConfigCliResourceSpecs();

        $this->assertSame('--cpu-weight=', $specs['CPUWeight']['cgroupFlag']);
        $this->assertSame('--io-write-bw=', $specs['IOWriteBW']['cgroupFlag']);
        $this->assertSame('--io-latency-ms=', $specs['ioLatencyMs']['cgroupFlag']);
        $this->assertFalse(isset($specs['trafficLimit']['cgroupFlag']));
        $this->assertFalse(isset($specs['cpuQuotaPercent']['cgroupFlag']));
    }
}
