<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/util/userConfigLighttpd.php';

class UserConfigLighttpdLogicTest extends TestCase
{
    public function testMemoryClampBounds(): void
    {
        $this->assertEquals(125, \pmssClampMemoryLimit(50));
        $this->assertEquals(512, \pmssClampMemoryLimit(512));
        $this->assertEquals(1024, \pmssClampMemoryLimit(5000));
    }

    public function testProcessPlanFollowsCpuQuota(): void
    {
        $plan = \pmssComputePhpProcessPlan(100);
        $this->assertEquals(2, $plan['max_procs']);
        $this->assertEquals(2, $plan['children']);
        $this->assertEquals(4, $plan['totalThreads']);

        $planHigh = \pmssComputePhpProcessPlan(250);
        $this->assertEquals(5, $planHigh['max_procs']);
        $this->assertEquals(10, $planHigh['totalThreads']);
    }

    public function testCpuQuotaLegacy85UsesThreadBasedDefault(): void
    {
        // Force a deterministic CPU thread count for the calculation.
        putenv('PMSS_TOTAL_CPU_THREADS=8');
        $props = ['CPUQuota' => '85%'];
        $policy = ['cpuQuotaPercent' => 85];

        $quota = \pmssExtractCpuQuotaPercent($props, $policy);
        // 8 logical threads × 85% = 680% effective quota.
        $this->assertEquals(680, $quota);
    }

    public function testCpuQuotaExplicitValuePreservedWhenNonLegacy(): void
    {
        putenv('PMSS_TOTAL_CPU_THREADS=8');
        $props = ['CPUQuota' => '250%'];
        $policy = ['cpuQuotaPercent' => 85];

        $quota = \pmssExtractCpuQuotaPercent($props, $policy);
        $this->assertEquals(250, $quota);
    }

    public function testCpuQuotaFallsBackToThreadsWhenMissing(): void
    {
        putenv('PMSS_TOTAL_CPU_THREADS=4');
        $props = [];
        $policy = [];

        $quota = \pmssExtractCpuQuotaPercent($props, $policy);
        // 4 logical threads × 85% = 340% effective quota.
        $this->assertEquals(340, $quota);
    }

    public function testParseSizeToMiB(): void
    {
        $this->assertEquals(500, \pmssParseSizeToMiB('524288000'));
        $this->assertEquals(500, \pmssParseSizeToMiB('500M'));
        $this->assertEquals(1, \pmssParseSizeToMiB('1024K'));
    }
}
