<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/UserConfigCgroupCliTrait.php';

class UserCgroupCliTasksAndCpuProfilesTest extends TestCase
{
    use UserConfigCgroupCliTrait;

    public function testTasksProfileLow(): void
    {
        $this->assertNamedProfile('--tasks-profile=low', 'TasksMax=1024');
    }

    public function testTasksProfileHigh(): void
    {
        $this->assertNamedProfile('--tasks-profile=high', 'TasksMax=8192');
    }

    public function testCpuProfileLow(): void
    {
        $this->assertNamedProfile('--cpu-profile=low', 'CPUWeight=50');
    }

    public function testCpuProfileHigh(): void
    {
        $this->assertNamedProfile('--cpu-profile=high', 'CPUWeight=300');
    }

    public function testMemProfileDefault(): void
    {
        $this->assertNamedProfile('--mem-profile=default', 'MemoryHigh=500M');
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

    private function assertNamedProfile(string $profileArg, string $expected): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', $profileArg]);
        $this->assertStringContainsString($expected, $out);
    }
}
