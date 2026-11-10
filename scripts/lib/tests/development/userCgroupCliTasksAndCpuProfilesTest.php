<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/UserConfigCgroupCliTrait.php';

class UserCgroupCliTasksAndCpuProfilesTest extends TestCase
{
    use UserConfigCgroupCliTrait;

    public function testTasksProfileLow(): void
    {
        $this->assertStringContainsString('TasksMax=1024',
            $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--tasks-profile=low']));
    }

    public function testTasksProfileHigh(): void
    {
        $this->assertStringContainsString('TasksMax=8192',
            $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--tasks-profile=high']));
    }

    public function testCpuProfileLow(): void
    {
        $this->assertStringContainsString('CPUWeight=50',
            $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--cpu-profile=low']));
    }

    public function testCpuProfileHigh(): void
    {
        $this->assertStringContainsString('CPUWeight=300',
            $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--cpu-profile=high']));
    }

    public function testMemProfileDefault(): void
    {
        $this->assertStringContainsString('MemoryHigh=500M',
            $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--mem-profile=default']));
    }

    public function testExplicitValuesOverrideNamedProfiles(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli([
            'root',
            '--apply',
            '--dry-run',
            '--cpu-profile=high',
            '--cpu-weight=111',
            '--tasks-profile=high',
            '--tasks-max=222',
            '--mem-profile=heavy',
            '--memory-high=333',
        ]);

        $this->assertStringContainsAndOmitsStrings(['CPUWeight=111', 'TasksMax=222', 'MemoryHigh=333M'],
            ['CPUWeight=300', 'TasksMax=8192', 'MemoryHigh=1024M'], $out);
    }
}
