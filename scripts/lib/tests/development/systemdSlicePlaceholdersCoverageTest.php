<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SystemdSlicePlaceholdersCoverageTest extends TestCase
{
    public function testAllPlaceholdersReplaced(): void
    {
        $tpl = "[Slice]\nCPUWeight=%%USER_CGROUP_CPU_WEIGHT%%\nIOWeight=%%USER_CGROUP_IO_WEIGHT%%\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\nMemoryHigh=%%USER_CGROUP_MEMORY_HIGH%%M\nMemoryMax=%%USER_CGROUP_MEMORY_MAX%%M\nCPUQuota=%%USER_CGROUP_CPU_QUOTA%%\n";
        $out = $this->pmssSystemdSliceDropinRender($this->pmssSystemdSliceFixturePrepare([
            'v2Template' => $tpl,
            'totalMemMiB' => 4096,
        ]));
        $this->assertTrue(strpos($out, '%%') === false, 'placeholders remained');
    }
}
