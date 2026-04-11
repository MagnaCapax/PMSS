<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class SystemdSlicePlaceholdersCoverageTest extends TestCase
{
    public function testAllPlaceholdersReplaced(): void
    {
        $out = $this->pmssSystemdSliceRender([
            'v2Template' => $this->pmssSystemdSliceTasksTemplate([
                'CPUWeight=%%USER_CGROUP_CPU_WEIGHT%%',
                'IOWeight=%%USER_CGROUP_IO_WEIGHT%%',
                '%%USER_CGROUP_IO_DEVICE_LATENCY%%',
                'MemoryHigh=%%USER_CGROUP_MEMORY_HIGH%%M',
                'MemoryMax=%%USER_CGROUP_MEMORY_MAX%%M',
                'CPUQuota=%%USER_CGROUP_CPU_QUOTA%%',
            ]),
            'totalMemMiB' => 4096,
        ]);
        $this->assertTrue(strpos($out, '%%') === false, 'placeholders remained');
    }
}
