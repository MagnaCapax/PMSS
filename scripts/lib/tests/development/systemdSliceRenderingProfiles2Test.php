<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SystemdSliceRenderingProfiles2Test extends TestCase
{
    public function testCpuQuotaRenderedFromPolicyDefault(): void
    {
        $tpl = "[Slice]\nCPUQuota=%%USER_CGROUP_CPU_QUOTA%%\n";
        $out = $this->pmssSystemdSliceDropinRender($this->pmssSystemdSliceFixturePrepare([
            'v2Template' => $tpl,
            'policy' => "<?php return ['cpuQuotaPercent'=>85];\n",
            'totalMemMiB' => 4096,
        ]));
        $this->assertTrue(strpos($out, 'CPUQuota=85%') !== false, 'CPUQuota percent not expanded');
    }

    public function testCpuQuotaInfinityPreserved(): void
    {
        $tpl = "[Slice]\nCPUQuota=%%USER_CGROUP_CPU_QUOTA%%\n";
        $out = $this->pmssSystemdSliceDropinRender($this->pmssSystemdSliceFixturePrepare([
            'cfgPrefix' => 'pmss-cg-cfginf-',
            'dropPrefix' => 'pmss-cg-dropinf-',
            'v2Template' => $tpl,
            'policy' => "<?php return ['cpuQuotaPercent'=>'infinity'];\n",
            'totalMemMiB' => 2048,
        ]));
        $this->assertTrue(strpos($out, 'CPUQuota=infinity') !== false, 'CPUQuota infinity not rendered');
    }

    public function testMountPolicyAppendsIoLinesWhenResolvable(): void
    {
        $tpl = "[Slice]\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\n";
        $policy = <<<'PHP'
<?php return [
  'tasksMax'=>512,
  'mounts' => [ '/' => ['readBw'=>'1M','writeBw'=>'2M'] ],
];
PHP;
        $out = $this->pmssSystemdSliceDropinRender($this->pmssSystemdSliceFixturePrepare([
            'cfgPrefix' => 'pmss-cg-cfg2-',
            'dropPrefix' => 'pmss-cg-drop2-',
            'v2Template' => $tpl,
            'policy' => $policy,
            'totalMemMiB' => 2048,
        ]));
        // Device string varies; assert IO lines present.
        $this->assertTrue(strpos($out, 'IOReadBandwidthMax=') !== false);
        $this->assertTrue(strpos($out, 'IOWriteBandwidthMax=') !== false);
    }
}
