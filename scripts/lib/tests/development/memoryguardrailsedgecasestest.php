<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class MemoryGuardrailsEdgeCasesTest extends TestCase
{
    private function tempDir(string $prefix): string
    {
        $d = sys_get_temp_dir().'/pmss-cg-'.bin2hex(random_bytes(4)).'-'.$prefix;
        @mkdir($d, 0700, true);
        return $d;
    }

    public function testLowRamFloorsAreApplied(): void
    {
        $cfgDir = $this->tempDir('cfg');
        $drop   = $this->tempDir('drop');
        $tpl = "[Slice]\nMemoryHigh=%%USER_CGROUP_MEMORY_HIGH%%M\nMemoryMax=%%USER_CGROUP_MEMORY_MAX%%M\n";
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v2.conf', $tpl);
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v1.conf', 'ignored');
        putenv('PMSS_CGROUP_MODE=v2');
        putenv('PMSS_CONFIG_DIR='.$cfgDir);
        putenv('PMSS_SYSTEMD_USER_SLICE_DIR='.$drop);
        putenv('PMSS_TOTAL_MEM_MIB=512');
        \pmssEnsureSystemdSlices('logmsg');
        $content = (string)file_get_contents($drop.'/15-pmss.conf');
        preg_match('/MemoryHigh=(\d+)M/', $content, $m1);
        preg_match('/MemoryMax=(\d+)M/', $content, $m2);
        $this->assertTrue(((int)$m1[1]) >= 250, 'MemoryHigh floor not enforced');
        $this->assertTrue(((int)$m2[1]) <= (int)floor(512*0.95), 'MemoryMax cap not enforced');
    }

    public function testHugeRamRespects95PercentCap(): void
    {
        $cfgDir = $this->tempDir('cfg2');
        $drop   = $this->tempDir('drop2');
        $tpl = "[Slice]\nMemoryHigh=%%USER_CGROUP_MEMORY_HIGH%%M\nMemoryMax=%%USER_CGROUP_MEMORY_MAX%%M\n";
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v2.conf', $tpl);
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v1.conf', 'ignored');
        putenv('PMSS_CGROUP_MODE=v2');
        putenv('PMSS_CONFIG_DIR='.$cfgDir);
        putenv('PMSS_SYSTEMD_USER_SLICE_DIR='.$drop);
        putenv('PMSS_TOTAL_MEM_MIB=65536');
        \pmssEnsureSystemdSlices('logmsg');
        $content = (string)file_get_contents($drop.'/15-pmss.conf');
        preg_match('/MemoryMax=(\d+)M/', $content, $m);
        $this->assertTrue(((int)$m[1]) <= (int)floor(65536*0.95));
    }
}

