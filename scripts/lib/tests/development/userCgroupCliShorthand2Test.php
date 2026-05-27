<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/UserConfigCgroupCliTrait.php';

class UserCgroupCliShorthand2Test extends TestCase
{
    use UserConfigCgroupCliTrait;

    public function testDeviceHomeResolutionPlannedIo(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--device=/home', '--io-profile=hdd'], [
            'PMSS_HOME_DEVICE' => '/dev/pmssHOME',
        ]);
        $this->assertStringContainsString('user=root', $out);
        $this->assertStringContainsString('Planned IO properties', $out);
        $this->assertStringContainsString('IOReadBandwidthMax=/dev/pmssHOME 5M', $out);
        $this->assertStringContainsString('IOWriteIOPSMax=/dev/pmssHOME 100', $out);
    }

    public function testSingleCpuWeightFlagOnly(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--cpu-weight=300']);
        $this->assertStringContainsString('Planned properties', $out);
        $this->assertStringContainsString('CPUWeight=300', $out);
        // Memory should not be planned unless explicitly provided
        $this->assertStringNotContainsString('MemoryHigh=', $out);
        $this->assertStringNotContainsString('MemoryMax=', $out);
    }

    public function testMemProfileHeavySetsHigh(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--mem-profile=heavy']);
        $this->assertStringContainsString('Planned properties', $out);
        $this->assertStringContainsString('MemoryHigh=1024M', $out);
    }
}
