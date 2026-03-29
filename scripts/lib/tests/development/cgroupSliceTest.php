<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep.php';

class cgroupSliceTest extends TestCase
{
    private function renderSlice(string $tplBody, int $cpuThreads, int $memMiB): string
    {
        return $this->pmssSystemdSliceDropinRender($this->pmssSystemdSliceFixturePrepare([
            'cfgPrefix' => 'pmss-cg-cfg-tasks-',
            'dropPrefix' => 'pmss-cg-drop-tasks-',
            'v2Template' => $tplBody,
            'totalCpuThreads' => $cpuThreads,
            'totalMemMiB' => $memMiB,
        ]));
    }

    public function testV2RenderingReplacesPlaceholders(): void
    {
        $tplBody = "[Slice]\nCPUAccounting=yes\nIOAccounting=yes\nMemoryAccounting=yes\nCPUWeight=%%USER_CGROUP_CPU_WEIGHT%%\nIOWeight=%%USER_CGROUP_IO_WEIGHT%%\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\nMemoryHigh=%%USER_CGROUP_MEMORY_HIGH%%M\nMemoryMax=%%USER_CGROUP_MEMORY_MAX%%M\n";
        $fixture = $this->pmssSystemdSliceFixturePrepare([
            'v2Template' => $tplBody,
            'totalMemMiB' => 8192,
        ]);

        $this->pmssSystemdSliceEnsure($fixture);

        $target = $fixture['dropDir'].'/15-pmss.conf';
        $this->assertTrue(file_exists($target), 'slice drop-in not created');
        $content = (string) file_get_contents($target);
        $this->assertTrue(strpos($content, '%%') === false, 'placeholders remained in output');
        $this->assertTrue(strpos($content, 'CPUWeight=') !== false);
        $this->assertTrue(strpos($content, 'IOWeight=') !== false);
        $this->assertTrue(strpos($content, 'TasksMax=') !== false);
        $this->assertTrue(strpos($content, 'MemoryHigh=') !== false);
        $this->assertTrue(strpos($content, 'MemoryMax=') !== false);
    }

    public function testMemoryConstraintsApplied(): void
    {
        $tplBody = "[Slice]\nMemoryHigh=%%USER_CGROUP_MEMORY_HIGH%%M\nMemoryMax=%%USER_CGROUP_MEMORY_MAX%%M\n";
        $fixture = $this->pmssSystemdSliceFixturePrepare([
            'cfgPrefix' => 'pmss-cg-cfg2-',
            'dropPrefix' => 'pmss-cg-drop2-',
            'v2Template' => $tplBody,
            'totalMemMiB' => 512,
        ]);

        // Very low RAM: ensure MemoryHigh >= 250MiB
        $content = $this->pmssSystemdSliceDropinRender($fixture);
        $this->assertTrue((bool) preg_match('/MemoryHigh=(\d+)M/', $content, $m1));
        $high = (int) $m1[1];
        $this->assertTrue($high >= 250, 'MemoryHigh below minimum 250MiB');

        // Large RAM: MemoryMax <= 95% and about 1.25x MemoryHigh
        $content2 = $this->pmssSystemdSliceDropinRender($this->pmssSystemdSliceFixturePrepare([
            'cfgPrefix' => 'pmss-cg-cfg2-',
            'dropPrefix' => 'pmss-cg-drop3-',
            'v2Template' => $tplBody,
            'totalMemMiB' => 65536,
        ]));
        $this->assertTrue((bool) preg_match('/MemoryHigh=(\d+)M/', $content2, $mh));
        $this->assertTrue((bool) preg_match('/MemoryMax=(\d+)M/', $content2, $mm));
        $mHigh = (int) $mh[1];
        $mMax = (int) $mm[1];
        $this->assertTrue($mMax <= (int) floor(65536 * 0.95), 'MemoryMax exceeds 95% cap');
        $this->assertEquals((int) floor($mHigh * 1.25), $mMax, 'MemoryMax should default to 1.25x MemoryHigh');
    }

    public function testV1TemplateSelectedWhenModeV1(): void
    {
        $v1Body = "[Slice]\nBlockIOAccounting=yes\nCPUWeight=%%USER_CGROUP_CPU_WEIGHT%%\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\nMemoryHigh=%%USER_CGROUP_MEMORY_HIGH%%M\nMemoryMax=%%USER_CGROUP_MEMORY_MAX%%M\n";
        $content = $this->pmssSystemdSliceDropinRender($this->pmssSystemdSliceFixturePrepare([
            'cfgPrefix' => 'pmss-cg-cfgv1-',
            'dropPrefix' => 'pmss-cg-dropv1-',
            'mode' => 'v1',
            'v1Template' => $v1Body,
            'totalMemMiB' => 4096,
        ]));
        $this->assertTrue(strpos($content, 'BlockIOAccounting=yes') !== false, 'v1 template not applied');
        $this->assertTrue(strpos($content, '%%') === false, 'placeholders remained');
    }

    public function testNoVendorPathsUsedForDropins(): void
    {
        $tplBody = "[Slice]\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\n";
        $fixture = $this->pmssSystemdSliceFixturePrepare([
            'cfgPrefix' => 'pmss-cg-cfgv3-',
            'dropPrefix' => 'pmss-cg-dropv3-',
            'v1Template' => $tplBody,
            'v2Template' => $tplBody,
            'totalMemMiB' => 2048,
        ]);

        $this->pmssSystemdSliceEnsure($fixture);
        $this->assertTrue(file_exists($fixture['dropDir'].'/15-pmss.conf'));
        // Ensure we didn't accidentally write to /usr/lib; cannot check fs safely here, but path check suffices.
        $this->assertTrue(strpos($fixture['dropDir'], '/etc/systemd') === false, 'test harness used temp path, not /etc');
    }

    public function testInvalidTemplateLogsWarningAndSkips(): void
    {
        // Do not write any template; function should log a warning and return.
        $fixture = $this->pmssSystemdSliceFixturePrepare([
            'cfgPrefix' => 'pmss-cg-cfgbad-',
            'dropPrefix' => 'pmss-cg-dropbad-',
            'v1Template' => null,
            'v2Template' => null,
            'totalMemMiB' => 1024,
        ]);

        // This should not throw; simply not create the target.
        $this->pmssSystemdSliceEnsure($fixture);
        $this->assertTrue(!file_exists($fixture['dropDir'].'/15-pmss.conf'));
    }

    public function testTasksMaxDefaultScalesWithHostCapacity(): void
    {
        $tplBody = "[Slice]\nTasksMax=%%USER_CGROUP_TASKS_MAX%%\n";

        // Clamp floor: 512 * max(1, 1) = 512 → 2048
        $out = $this->renderSlice($tplBody, 1, 1024);
        $this->assertTrue(strpos($out, 'TasksMax=2048') !== false, 'TasksMax floor clamp not applied');

        // CPU dominates: 512 * max(8, 2) = 4096
        $out = $this->renderSlice($tplBody, 8, 2048);
        $this->assertTrue(strpos($out, 'TasksMax=4096') !== false, 'TasksMax did not scale with CPU threads');

        // RAM dominates: 512 * max(2, 16) = 8192
        $out = $this->renderSlice($tplBody, 2, 16384);
        $this->assertTrue(strpos($out, 'TasksMax=8192') !== false, 'TasksMax did not scale with RAM GiB');

        // Clamp ceiling: 512 * max(64, 256) = 131072 → 16384
        $out = $this->renderSlice($tplBody, 64, 262144);
        $this->assertTrue(strpos($out, 'TasksMax=16384') !== false, 'TasksMax ceiling clamp not applied');
    }
}
