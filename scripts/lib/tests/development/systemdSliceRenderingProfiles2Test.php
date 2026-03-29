<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SystemdSliceRenderingProfiles2Test extends TestCase
{
    public function testCpuQuotaRenderedFromPolicyDefault(): void
    {
        $out = $this->pmssSystemdSliceRender([
            'v2Template' => "[Slice]\nCPUQuota=%%USER_CGROUP_CPU_QUOTA%%\n",
            'policy' => $this->pmssSystemdSlicePolicySource(['cpuQuotaPercent' => 85]),
            'totalMemMiB' => 4096,
        ]);
        $this->assertTrue(strpos($out, 'CPUQuota=85%') !== false, 'CPUQuota percent not expanded');
    }

    public function testCpuQuotaInfinityPreserved(): void
    {
        $out = $this->pmssSystemdSliceRender([
            'cfgPrefix' => 'pmss-cg-cfginf-',
            'dropPrefix' => 'pmss-cg-dropinf-',
            'v2Template' => "[Slice]\nCPUQuota=%%USER_CGROUP_CPU_QUOTA%%\n",
            'policy' => $this->pmssSystemdSlicePolicySource(['cpuQuotaPercent' => 'infinity']),
            'totalMemMiB' => 2048,
        ]);
        $this->assertTrue(strpos($out, 'CPUQuota=infinity') !== false, 'CPUQuota infinity not rendered');
    }

    public function testMountPolicyAppendsIoLinesWhenResolvable(): void
    {
        $out = $this->pmssSystemdSliceRender([
            'cfgPrefix' => 'pmss-cg-cfg2-',
            'dropPrefix' => 'pmss-cg-drop2-',
            'v2Template' => $this->pmssSystemdSliceTasksTemplate(),
            'policy' => $this->pmssSystemdSlicePolicySource([
                'tasksMax' => 512,
                'mounts' => ['/'=> ['readBw' => '1M', 'writeBw' => '2M']],
            ]),
            'totalMemMiB' => 2048,
        ]);
        // Device string varies; assert IO lines present.
        $this->assertTrue(strpos($out, 'IOReadBandwidthMax=') !== false);
        $this->assertTrue(strpos($out, 'IOWriteBandwidthMax=') !== false);
    }
}
