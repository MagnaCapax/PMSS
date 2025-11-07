<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserCgroupCliProfilesTest extends TestCase
{
    public function testCpuProfileLowSetsWeight50(): void
    {
        $out = @shell_exec('php scripts/util/userCgroup.php root --dry-run --cpu-profile=low');
        $this->assertTrue(strpos((string)$out, 'CPUWeight=50') !== false);
    }

    public function testTasksProfileHighSets8192(): void
    {
        $out = @shell_exec('php scripts/util/userCgroup.php root --dry-run --tasks-profile=high');
        $this->assertTrue(strpos((string)$out, 'TasksMax=8192') !== false);
    }

    public function testMemProfileHeavyDerivesMax(): void
    {
        $out = (string)@shell_exec('php scripts/util/userCgroup.php root --dry-run --mem-profile=heavy');
        $this->assertTrue(strpos($out, 'MemoryHigh=') !== false);
        $this->assertTrue(strpos($out, 'MemoryMax=') !== false);
    }

    public function testDeviceHomeResolveUsesEnv(): void
    {
        $env = 'PMSS_HOME_DEVICE=/dev/testhome '; // prefix to export var for shell_exec portable sets is tricky; skip
        putenv('PMSS_HOME_DEVICE=/dev/testhome');
        $out = (string)@shell_exec('php scripts/util/userCgroup.php root --dry-run --device=/home --io-profile=hdd');
        $this->assertTrue(strpos($out, 'IOReadBandwidthMax=/dev/testhome 5M') !== false, 'device resolution failed');
        // cleanup
        putenv('PMSS_HOME_DEVICE');
    }

    public function testAdditiveSingleChangeOnlyCpuWeight(): void
    {
        $out = (string)@shell_exec('php scripts/util/userCgroup.php root --dry-run --cpu-weight=333');
        $this->assertTrue(strpos($out, 'CPUWeight=333') !== false);
        $this->assertTrue(strpos($out, 'IOWeight=') === false);
        $this->assertTrue(strpos($out, 'TasksMax=') === false);
    }

    public function testWipeDryRunDoesNotApply(): void
    {
        $out = (string)@shell_exec('php scripts/util/userCgroup.php root --dry-run --wipe');
        $this->assertTrue(strpos($out, 'dry-run') !== false);
    }
}

