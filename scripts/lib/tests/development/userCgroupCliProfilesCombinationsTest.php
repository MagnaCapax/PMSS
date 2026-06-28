<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/UserConfigCgroupCliTrait.php';

class UserCgroupCliProfilesCombinationsTest extends TestCase
{
    use UserConfigCgroupCliTrait;

    public function testNvmeProfileNoIoLimits(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--device=/home', '--io-profile=nvme'], [
            'PMSS_HOME_DEVICE' => '/dev/pmssNVME',
        ]);
        $this->assertStringContainsString('IOWeight=200', $out);
        $this->assertStringNotContainsString('IOReadBandwidthMax=', $out);
    }

    public function testBulkProfileRaisesWeights(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--device=/home', '--io-profile=bulk'], [
            'PMSS_HOME_DEVICE' => '/dev/pmssBULK',
        ]);
        $this->assertStringContainsAllStrings(['IOWeight=500', 'CPUWeight=300', 'TasksMax=8192'], $out);
    }

    public function testCombinedNumericProfilesMatchSnapshot(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--cpu-profile=low', '--tasks-profile=high', '--mem-profile=heavy']);

        $this->assertStringContainsString("MemoryHigh=1024M\nMemoryMax=1280M\nCPUWeight=50\nIOWeight=200\nTasksMax=8192", $out);
        $this->assertSame('eb53ce76e44dd770c4cf49c9d967c6d079c48bf705c60af09ce151e77e2e3c04', hash('sha256', $out));
    }

    public function testExplicitIoWeightOverridesProfile(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--device=/home', '--io-profile=bulk', '--io-weight=777'], [
            'PMSS_HOME_DEVICE' => '/dev/pmssBULK',
        ]);
        $this->assertStringContainsString('IOWeight=777', $out);
    }

    public function testInvalidDevicePathSkipsIoLines(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--device=/nope', '--io-profile=hdd']);
        $this->assertStringNotContainsString('IOReadBandwidthMax=', $out);
    }

    public function testExplicitIoReadBwFlagProducesIoLine(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--io-read-bw=/dev/sda:3M']);
        $this->assertStringContainsString('IOReadBandwidthMax=/dev/sda 3M', $out);
    }
}
