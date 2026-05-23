<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/UserConfigCgroupCliTrait.php';

class UserCgroupCliTasksAndCpuProfilesTest extends TestCase
{
    use UserConfigCgroupCliTrait;

    public function testTasksProfileLow(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--tasks-profile=low']);
        $this->assertStringContainsString('TasksMax=1024', $out);
    }

    public function testTasksProfileHigh(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--tasks-profile=high']);
        $this->assertStringContainsString('TasksMax=8192', $out);
    }

    public function testCpuProfileLow(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--cpu-profile=low']);
        $this->assertStringContainsString('CPUWeight=50', $out);
    }

    public function testCpuProfileHigh(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--cpu-profile=high']);
        $this->assertStringContainsString('CPUWeight=300', $out);
    }

    public function testMemProfileDefault(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--mem-profile=default']);
        $this->assertStringContainsString('MemoryHigh=500M', $out);
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

        $this->assertStringContainsString('CPUWeight=111', $out);
        $this->assertStringContainsString('TasksMax=222', $out);
        $this->assertStringContainsString('MemoryHigh=333M', $out);
        $this->assertStringNotContainsString('CPUWeight=300', $out);
        $this->assertStringNotContainsString('TasksMax=8192', $out);
        $this->assertStringNotContainsString('MemoryHigh=1024M', $out);
    }
}
