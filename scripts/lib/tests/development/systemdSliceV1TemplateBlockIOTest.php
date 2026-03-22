<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SystemdSliceV1TemplateBlockIOTest extends TestCase
{
    public function testV1TemplateIncludesBlockIOAccounting(): void
    {
        $cfgDir = $this->pmssMakeTempDir('pmss-cg-cfgv1b-');
        $drop   = $this->pmssMakeTempDir('pmss-cg-dropv1b-');
        $v1Body = "[Slice]\nBlockIOAccounting=yes\nCPUWeight=%%USER_CGROUP_CPU_WEIGHT%%\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\nMemoryHigh=%%USER_CGROUP_MEMORY_HIGH%%M\nMemoryMax=%%USER_CGROUP_MEMORY_MAX%%M\n";
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v1.conf', $v1Body);
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v2.conf', 'ignored');
        putenv('PMSS_CGROUP_MODE=v1');
        putenv('PMSS_CONFIG_DIR='.$cfgDir);
        putenv('PMSS_SYSTEMD_USER_SLICE_DIR='.$drop);
        putenv('PMSS_TOTAL_MEM_MIB=2048');
        \pmssEnsureSystemdSlices('logmsg');
        $out = (string)file_get_contents($drop.'/15-pmss.conf');
        $this->assertStringContainsString('BlockIOAccounting=yes', $out);
    }
}
