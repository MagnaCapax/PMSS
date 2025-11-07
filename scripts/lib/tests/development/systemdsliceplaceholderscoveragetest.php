<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SystemdSlicePlaceholdersCoverageTest extends TestCase
{
    private function tempDir(string $prefix): string
    {
        $d = sys_get_temp_dir().'/pmss-cg-'.bin2hex(random_bytes(4)).'-'.$prefix;
        @mkdir($d, 0700, true);
        return $d;
    }

    public function testAllPlaceholdersReplaced(): void
    {
        $cfgDir = $this->tempDir('cfg');
        $drop   = $this->tempDir('drop');
        $tpl = "[Slice]\nCPUWeight=%%USER_CGROUP_CPU_WEIGHT%%\nIOWeight=%%USER_CGROUP_IO_WEIGHT%%\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\nMemoryHigh=%%USER_CGROUP_MEMORY_HIGH%%M\nMemoryMax=%%USER_CGROUP_MEMORY_MAX%%M\nCPUQuota=%%USER_CGROUP_CPU_QUOTA%%\n";
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v2.conf', $tpl);
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v1.conf', 'ignored');
        putenv('PMSS_CGROUP_MODE=v2');
        putenv('PMSS_CONFIG_DIR='.$cfgDir);
        putenv('PMSS_SYSTEMD_USER_SLICE_DIR='.$drop);
        putenv('PMSS_TOTAL_MEM_MIB=4096');
        \pmssEnsureSystemdSlices('logmsg');
        $out = (string)file_get_contents($drop.'/15-pmss.conf');
        $this->assertTrue(strpos($out, '%%') === false, 'placeholders remained');
    }
}

