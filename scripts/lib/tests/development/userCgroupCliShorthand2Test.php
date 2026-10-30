<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/UserConfigCgroupCliTrait.php';

class UserCgroupCliShorthand2Test extends TestCase
{
    use UserConfigCgroupCliTrait;

    public function testDeviceHomeResolutionPlannedIo(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--device=/home', '--io-profile=hdd'], [
            'PMSS_HOME_DEVICE' => '/dev/pmssHOME',
        ]);
        $this->assertStringContainsAllStrings(['user=root', 'Planned IO properties', 'IOReadBandwidthMax=/dev/pmssHOME 5M', 'IOWriteIOPSMax=/dev/pmssHOME 100'], $out);
    }

    public function testSingleCpuWeightFlagOnly(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--cpu-weight=300']);
        $this->assertStringContainsAllStrings(['Planned properties', 'CPUWeight=300'], $out);
        // Memory should not be planned unless explicitly provided
        $this->assertStringContainsAndOmitsStrings([], ['MemoryHigh=', 'MemoryMax='], $out);
    }

    public function testMemProfileHeavySetsHigh(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--mem-profile=heavy']);
        $this->assertStringContainsAllStrings(['Planned properties', 'MemoryHigh=1024M'], $out);
    }
}
