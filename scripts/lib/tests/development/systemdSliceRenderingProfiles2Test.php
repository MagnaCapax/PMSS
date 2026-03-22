<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SystemdSliceRenderingProfiles2Test extends TestCase
{
    public function testCpuQuotaRenderedFromPolicyDefault(): void
    {
        $cfgDir = $this->pmssMakeTempDir('pmss-cg-cfg-');
        $drop   = $this->pmssMakeTempDir('pmss-cg-drop-');
        // Minimal v2 template with CPUQuota placeholder
        $tpl = "[Slice]\nCPUQuota=%%USER_CGROUP_CPU_QUOTA%%\n";
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v2.conf', $tpl);
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v1.conf', 'ignored');
        // Policy file with default quota percent 85
        file_put_contents($cfgDir.'/cgroup.policy.php', "<?php return ['cpuQuotaPercent'=>85];\n");

        putenv('PMSS_CGROUP_MODE=v2');
        putenv('PMSS_CONFIG_DIR='.$cfgDir);
        putenv('PMSS_SYSTEMD_USER_SLICE_DIR='.$drop);
        putenv('PMSS_TOTAL_MEM_MIB=4096');
        \pmssEnsureSystemdSlices('logmsg');
        $out = (string)file_get_contents($drop.'/15-pmss.conf');
        $this->assertTrue(strpos($out, 'CPUQuota=85%') !== false, 'CPUQuota percent not expanded');
    }

    public function testCpuQuotaInfinityPreserved(): void
    {
        $cfgDir = $this->pmssMakeTempDir('pmss-cg-cfginf-');
        $drop   = $this->pmssMakeTempDir('pmss-cg-dropinf-');
        $tpl = "[Slice]\nCPUQuota=%%USER_CGROUP_CPU_QUOTA%%\n";
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v2.conf', $tpl);
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v1.conf', 'ignored');
        file_put_contents($cfgDir.'/cgroup.policy.php', "<?php return ['cpuQuotaPercent'=>'infinity'];\n");

        putenv('PMSS_CGROUP_MODE=v2');
        putenv('PMSS_CONFIG_DIR='.$cfgDir);
        putenv('PMSS_SYSTEMD_USER_SLICE_DIR='.$drop);
        putenv('PMSS_TOTAL_MEM_MIB=2048');
        \pmssEnsureSystemdSlices('logmsg');
        $out = (string)file_get_contents($drop.'/15-pmss.conf');
        $this->assertTrue(strpos($out, 'CPUQuota=infinity') !== false, 'CPUQuota infinity not rendered');
    }

    public function testMountPolicyAppendsIoLinesWhenResolvable(): void
    {
        $cfgDir = $this->pmssMakeTempDir('pmss-cg-cfg2-');
        $drop   = $this->pmssMakeTempDir('pmss-cg-drop2-');
        $tpl = "[Slice]\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\n";
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v2.conf', $tpl);
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v1.conf', 'ignored');
        $policy = <<<'PHP'
<?php return [
  'tasksMax'=>512,
  'mounts' => [ '/' => ['readBw'=>'1M','writeBw'=>'2M'] ],
];
PHP;
        file_put_contents($cfgDir.'/cgroup.policy.php', $policy);
        putenv('PMSS_CGROUP_MODE=v2');
        putenv('PMSS_CONFIG_DIR='.$cfgDir);
        putenv('PMSS_SYSTEMD_USER_SLICE_DIR='.$drop);
        putenv('PMSS_TOTAL_MEM_MIB=2048');
        \pmssEnsureSystemdSlices('logmsg');
        $out = (string)file_get_contents($drop.'/15-pmss.conf');
        // Device string varies; assert IO lines present
        $this->assertTrue(strpos($out, 'IOReadBandwidthMax=') !== false);
        $this->assertTrue(strpos($out, 'IOWriteBandwidthMax=') !== false);
    }
}
