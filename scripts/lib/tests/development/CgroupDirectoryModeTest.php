<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep/cgroupDirectoryMode.php';

class CgroupDirectoryModeTest extends TestCase
{
    public function testV1ParentsOnly(): void
    {
        $root = $this->pmssMakeTempDir('pmss-cgroup-', 0700);
        mkdir($root.'/memory/user.slice', 0755, true);
        mkdir($root.'/pids/user.slice/user-1000.slice', 0755, true);
        chmod($root.'/pids/user.slice/user-1000.slice', 0755);
        $paths = pmssCgroupUserSliceParentPaths($root);
        $this->assertSame([$root.'/memory/user.slice', $root.'/pids/user.slice'], $paths);
        pmssCgroupDirectoryModeApply(static function (): void {}, $root);
        clearstatcache();
        $this->assertSame(0711, fileperms($root.'/memory/user.slice') & 0777);
        $this->assertSame(0711, fileperms($root.'/pids/user.slice') & 0777);
        $this->assertSame(0755, fileperms($root.'/pids/user.slice/user-1000.slice') & 0777);
    }

    public function testV2ParentOnly(): void
    {
        $root = $this->pmssMakeTempDir('pmss-cgroup-', 0700);
        file_put_contents($root.'/cgroup.controllers', 'cpu memory');
        mkdir($root.'/user.slice', 0755);
        $this->assertSame([$root.'/user.slice'], pmssCgroupUserSliceParentPaths($root));
        pmssCgroupDirectoryModeApply(static function (): void {}, $root);
        clearstatcache();
        $this->assertSame(0711, fileperms($root.'/user.slice') & 0777);
    }

    public function testBootAndUpdateApplySameParentMode(): void
    {
        $repo = dirname(__DIR__, 4);
        $boot = file_get_contents($repo.'/etc/seedbox/config/template.pmss-boot-tuning.sh');
        $update = file_get_contents($repo.'/scripts/util/update-step2.php');
        $this->assertStringContainsString('chmod 0711 /sys/fs/cgroup/user.slice', $boot);
        $this->assertStringContainsString('chmod 0711 "$slice"', $boot);
        $this->assertStringContainsString("'pmssCgroupDirectoryModeApply'", $update);
    }
}
