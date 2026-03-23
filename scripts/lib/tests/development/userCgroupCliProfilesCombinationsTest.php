<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
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
        $this->assertTrue(strpos($out, 'IOReadBandwidthMax=') === false);
    }

    public function testBulkProfileRaisesWeights(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--device=/home', '--io-profile=bulk'], [
            'PMSS_HOME_DEVICE' => '/dev/pmssBULK',
        ]);
        $this->assertStringContainsString('IOWeight=500', $out);
        $this->assertStringContainsString('CPUWeight=300', $out);
        $this->assertStringContainsString('TasksMax=8192', $out);
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
        $this->assertTrue(strpos($out, 'IOReadBandwidthMax=') === false);
    }

    public function testExplicitIoReadBwFlagProducesIoLine(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--io-read-bw=/dev/sda:3M']);
        $this->assertStringContainsString('IOReadBandwidthMax=/dev/sda 3M', $out);
    }
}
