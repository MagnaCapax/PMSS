<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SystemdSliceRootOverrideWrittenTest extends TestCase
{
    public function testRootUnlimitedDropinCreated(): void
    {
        $base = $this->pmssMakeTempDir('pmss-cg-basedir-');
        $drop = $base.'/user-.slice.d';
        @mkdir($drop, 0755, true);

        $fixture = $this->pmssSystemdSliceFixturePrepare([
            'dropDir' => $drop,
            'v2Template' => "[Slice]\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\n",
            'totalMemMiB' => 1024,
        ]);

        $this->pmssSystemdSliceEnsure($fixture);

        $rootDrop = $base.'/user-0.slice.d/99-zz-pmss-unlimited.conf';
        $this->assertTrue(file_exists($rootDrop), 'Root override missing');
        $data = (string) file_get_contents($rootDrop);
        $this->assertStringContainsString('TasksMax=infinity', $data);
        $this->assertStringContainsString('MemoryHigh=infinity', $data);
        $this->assertStringContainsString('MemoryMax=infinity', $data);
    }
}
