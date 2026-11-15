<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SystemdSliceRenderingProfiles2Test extends TestCase
{
    public function testCpuQuotaRenderedFromPolicyDefault(): void
    {
        $this->assertStringContainsString('CPUQuota=85%', $this->pmssSystemdSliceRender([
            'v2Template' => "[Slice]\nCPUQuota=%%USER_CGROUP_CPU_QUOTA%%\n",
            'policy' => ['cpuQuotaPercent' => 85],
            'totalMemMiB' => 4096,
        ]), 'CPUQuota percent not expanded');
    }

    public function testCpuQuotaInfinityPreserved(): void
    {
        $this->assertStringContainsString('CPUQuota=infinity', $this->pmssSystemdSliceRender([
            'cfgPrefix' => 'pmss-cg-cfginf-',
            'dropPrefix' => 'pmss-cg-dropinf-',
            'v2Template' => "[Slice]\nCPUQuota=%%USER_CGROUP_CPU_QUOTA%%\n",
            'policy' => ['cpuQuotaPercent' => 'infinity'],
            'totalMemMiB' => 2048,
        ]), 'CPUQuota infinity not rendered');
    }

    public function testMountPolicyAppendsIoLinesWhenResolvable(): void
    {
        // Device string varies; assert IO lines present.
        $this->assertStringContainsAllStrings(['IOReadBandwidthMax=', 'IOWriteBandwidthMax='], $this->pmssSystemdSliceRender([
            'cfgPrefix' => 'pmss-cg-cfg2-',
            'dropPrefix' => 'pmss-cg-drop2-',
            'v2Template' => $this->pmssSystemdSliceTasksTemplate(),
            'policy' => [
                'tasksMax' => 512,
                'mounts' => ['/'=> ['readBw' => '1M', 'writeBw' => '2M']],
            ],
            'totalMemMiB' => 2048,
        ]));
    }
}
