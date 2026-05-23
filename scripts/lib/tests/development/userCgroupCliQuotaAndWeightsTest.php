<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/UserConfigCgroupCliTrait.php';

class UserCgroupCliQuotaAndWeightsTest extends TestCase
{
    use UserConfigCgroupCliTrait;

    public function testCpuQuotaPercentIsPlanned(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--memory-high=500', '--cpu-quota-percent=70']);
        $this->assertStringContainsString('CPUQuota=70%', $out);
    }

    public function testDerivedWeightsFromMemoryHigh(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--memory-high=1600']);
        // 8 * sqrt(1600) = 320 for both CPUWeight and IOWeight. The systemd-side
        // cgroup-v1 BFQ chain caps the effective kernel bfq.weight independently;
        // /scripts/cron/cgroupBfqWeightApply.php enforces the real kernel-level cap.
        $this->assertStringContainsString('CPUWeight=320', $out);
        $this->assertStringContainsString('IOWeight=320', $out);
    }

    public function testExplicitCpuWeightOverridesDerived(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--memory-high=1600', '--cpu-weight=50']);
        $this->assertStringContainsString('CPUWeight=50', $out);
        // IOWeight still derives from memory when --io-weight is not set.
        $this->assertStringContainsString('IOWeight=320', $out);
    }

    public function testIoProfileBulkExpandsWeightsAndTasks(): void
    {
        $env = ['PMSS_HOME_DEVICE' => '/dev/null'];
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--device=/home', '--io-profile=bulk'], $env);
        $this->assertStringContainsString('IOWeight=500', $out);
        $this->assertStringContainsString('CPUWeight=300', $out);
        $this->assertStringContainsString('TasksMax=8192', $out);
    }
}
