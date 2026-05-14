<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/cgroupPolicyRefresh.php';

class cgroupPolicyRefreshTest extends TestCase
{
    public function testHasExplicitIoPolicyRequiresLatencyOrCostKnobs(): void
    {
        $this->assertFalse(\pmssCgroupRefreshHasExplicitIoPolicy(['ramMiB' => 512]));
        $this->assertTrue(\pmssCgroupRefreshHasExplicitIoPolicy(['ioLatencyMs' => 50]));
        $this->assertTrue(\pmssCgroupRefreshHasExplicitIoPolicy(['ioCostQos' => 'enable=1 ctrl=user']));
        $this->assertTrue(\pmssCgroupRefreshHasExplicitIoPolicy(['ioCostModel' => 'ctrl=user model=linear']));
    }

    public function testBuildCommandCarriesIoCostAndLatencyFlags(): void
    {
        $command = \pmssCgroupRefreshBuildCommand('alice', [
            'ramMiB' => 1024,
            'CPUWeight' => 200,
            'ioLatencyMs' => 50,
            'ioCostQos' => 'enable=1 ctrl=user',
            'ioCostModel' => 'ctrl=user model=linear',
        ]);

        $this->assertTrue(is_string($command));
        $this->assertStringContainsAllStrings([
            "'/scripts/util/userConfigCgroup.php' 'alice' '--apply' '--memory-high=1024'",
            "'--cpu-weight=200'",
            "'--io-latency-ms=50'",
            "'--io-cost-qos=enable=1 ctrl=user'",
            "'--io-cost-model=ctrl=user model=linear'",
        ], $command);
    }

    public function testBuildCommandReturnsNullWithoutMemoryBaseline(): void
    {
        $this->assertSame(null, \pmssCgroupRefreshBuildCommand('alice', ['ioLatencyMs' => 50]));
    }

    public function testRootCronSchedulesCgroupPolicyRefresh(): void
    {
        $this->pmssAssertRepoFileContainsString(
            'etc/seedbox/config/root.cron',
            '/scripts/cron/cgroupPolicyRefresh.php',
            'root.cron should schedule cgroupPolicyRefresh.php'
        );
        $this->pmssAssertRepoFileSubstringCount(
            'etc/seedbox/config/root.cron',
            '/scripts/cron/cgroupPolicyRefresh.php',
            2,
            'Expected cgroupPolicyRefresh.php to run at reboot and periodically'
        );
    }
}
