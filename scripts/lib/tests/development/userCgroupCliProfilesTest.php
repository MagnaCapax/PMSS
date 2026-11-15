<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/UserConfigCgroupCliTrait.php';

class UserCgroupCliProfilesTest extends TestCase
{
    use UserConfigCgroupCliTrait;

    public function testCpuProfileLowSetsWeight50(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--dry-run', '--cpu-profile=low']);
        $this->assertStringContainsString('CPUWeight=50', (string)$out);
    }

    public function testTasksProfileHighSets8192(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--dry-run', '--tasks-profile=high']);
        $this->assertStringContainsString('TasksMax=8192', (string)$out);
    }

    public function testMemProfileHeavyDerivesMax(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--dry-run', '--mem-profile=heavy']);
        $this->assertStringContainsAllStrings(['MemoryHigh=', 'MemoryMax='], $out);
    }

    public function testDeviceHomeResolveUsesEnv(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(
            ['root', '--dry-run', '--device=/home', '--io-profile=hdd'],
            ['PMSS_HOME_DEVICE' => '/dev/testhome']
        );
        $this->assertStringContainsString('IOReadBandwidthMax=/dev/testhome 5M', $out, 'device resolution failed');
    }

    public function testAdditiveSingleChangeOnlyCpuWeight(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--dry-run', '--cpu-weight=333']);
        $this->assertStringContainsAndOmitsStrings(['CPUWeight=333'], ['IOWeight=', 'TasksMax='], $out);
    }

    public function testWipeDryRunDoesNotApply(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--dry-run', '--wipe']);
        $this->assertStringContainsString('dry-run', $out);
    }
}
