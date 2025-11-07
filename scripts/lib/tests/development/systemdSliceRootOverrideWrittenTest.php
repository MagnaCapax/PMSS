<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SystemdSliceRootOverrideWrittenTest extends TestCase
{
    private function tempDir(string $prefix): string
    {
        $d = sys_get_temp_dir().'/pmss-cg-'.bin2hex(random_bytes(4)).'-'.$prefix;
        @mkdir($d, 0700, true);
        return $d;
    }

    public function testRootUnlimitedDropinCreated(): void
    {
        $base = $this->tempDir('basedir');
        $drop = $base.'/user-.slice.d';
        @mkdir($drop, 0755, true);
        $cfgDir = $this->tempDir('cfg');
        $tpl = "[Slice]\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\n";
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v2.conf', $tpl);
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v1.conf', 'ignored');
        putenv('PMSS_CGROUP_MODE=v2');
        putenv('PMSS_CONFIG_DIR='.$cfgDir);
        putenv('PMSS_SYSTEMD_USER_SLICE_DIR='.$drop);
        putenv('PMSS_TOTAL_MEM_MIB=1024');
        \pmssEnsureSystemdSlices('logmsg');
        $rootDrop = $base.'/user-0.slice.d/99-pmss-unlimited.conf';
        $this->assertTrue(file_exists($rootDrop), 'Root override missing');
        $data = (string)file_get_contents($rootDrop);
        $this->assertStringContainsString('MemoryHigh=infinity', $data);
        $this->assertStringContainsString('TasksMax=infinity', $data);
    }
}

