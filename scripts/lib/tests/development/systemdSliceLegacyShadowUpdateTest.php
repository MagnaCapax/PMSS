<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SystemdSliceLegacyShadowUpdateTest extends TestCase
{
    private function tempDir(string $prefix): string
    {
        $d = sys_get_temp_dir().'/pmss-cg-'.bin2hex(random_bytes(4)).'-'.$prefix;
        @mkdir($d, 0700, true);
        return $d;
    }

    public function testLegacyShadowFileRewrittenEvenWithoutVendorDropin(): void
    {
        $base = $this->tempDir('shadow');
        $drop = $base.'/user-.slice.d';
        @mkdir($drop, 0755, true);

        $cfgDir = $this->tempDir('cfg-shadow');
        $tpl    = "[Slice]\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\n";
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v2.conf', $tpl);
        file_put_contents($cfgDir.'/template.cgroup.user-slice.v1.conf', 'ignored');

        $legacyShadow = $drop.'/99-pmss.conf';
        file_put_contents($legacyShadow, "[Slice]\nTasksMax=1000\n");

        putenv('PMSS_CGROUP_MODE=v2');
        putenv('PMSS_CONFIG_DIR='.$cfgDir);
        putenv('PMSS_SYSTEMD_USER_SLICE_DIR='.$drop);
        putenv('PMSS_TOTAL_CPU_THREADS=2');
        putenv('PMSS_TOTAL_MEM_MIB=1024');

        \pmssEnsureSystemdSlices('logmsg');

        $this->assertTrue(file_exists($legacyShadow), 'Legacy shadow file missing after run');
        $data = (string) file_get_contents($legacyShadow);
        $this->assertStringContainsString('TasksMax=2048', $data, 'Legacy TasksMax not rewritten to expected floor clamp');
    }
}

