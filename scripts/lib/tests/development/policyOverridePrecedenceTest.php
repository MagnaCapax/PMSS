<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/UserConfigCgroupCliTrait.php';

class PolicyOverridePrecedenceTest extends TestCase
{
    use UserConfigCgroupCliTrait;

    private function createPolicyDir(string $suffix, string $body): string
    {
        $cfgDir = $this->pmssMakeNamedTempDir('pmss-cg-'.$suffix.'-', 0700);
        file_put_contents($cfgDir.'/cgroup.policy.php', $body);
        return $cfgDir;
    }

    public function testDefaultsApplyWhenExplicitMissing(): void
    {
        $cfgDir = $this->createPolicyDir('policy', "<?php return ['cpuWeight'=>123,'ioWeight'=>321,'tasksMax'=>777];\n");
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--defaults'], ['PMSS_CONFIG_DIR' => $cfgDir]);
        $this->assertStringContainsString('CPUWeight=123', $out);
        $this->assertStringContainsString('IOWeight=321', $out);
        $this->assertStringContainsString('TasksMax=777', $out);
    }

    public function testExplicitOverridesPolicyDefaults(): void
    {
        $cfgDir = $this->createPolicyDir('policy2', "<?php return ['cpuWeight'=>111];\n");
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--defaults', '--cpu-weight=999'], ['PMSS_CONFIG_DIR' => $cfgDir]);
        $this->assertStringContainsString('CPUWeight=999', $out);
    }

    public function testDefaultsExpandPolicyMountIoPairs(): void
    {
        $cfgDir = $this->createPolicyDir(
            'mount-io',
            "<?php return ['mounts' => ['/home' => ['ioWeight' => 320, 'readBw' => '25M', 'writeBw' => '10M', 'readIops' => 150, 'writeIops' => 90]]];\n"
        );

        $out = $this->pmssRunUserConfigCgroupCli(
            ['root', '--apply', '--dry-run', '--defaults'],
            ['PMSS_CONFIG_DIR' => $cfgDir, 'PMSS_HOME_DEVICE' => '/dev/testhome']
        );

        $this->assertStringContainsString('IODeviceWeight=/dev/testhome 320', $out);
        $this->assertStringContainsString('IOReadBandwidthMax=/dev/testhome 25M', $out);
        $this->assertStringContainsString('IOWriteBandwidthMax=/dev/testhome 10M', $out);
        $this->assertStringContainsString('IOReadIOPSMax=/dev/testhome 150', $out);
        $this->assertStringContainsString('IOWriteIOPSMax=/dev/testhome 90', $out);
    }

    public function testExplicitIoFlagsOverridePolicyMountIoPairs(): void
    {
        $cfgDir = $this->createPolicyDir(
            'mount-io-explicit',
            "<?php return ['mounts' => ['/home' => ['readBw' => '25M']]];\n"
        );

        $out = $this->pmssRunUserConfigCgroupCli(
            ['root', '--apply', '--dry-run', '--defaults', '--io-read-bw=/dev/manual:9M'],
            ['PMSS_CONFIG_DIR' => $cfgDir, 'PMSS_HOME_DEVICE' => '/dev/testhome']
        );

        $this->assertStringContainsString('IOReadBandwidthMax=/dev/manual 9M', $out);
        $this->assertTrue(strpos($out, 'IOReadBandwidthMax=/dev/testhome 25M') === false);
    }

    public function testIoProfileOverridesPolicyMountIoPairs(): void
    {
        $cfgDir = $this->createPolicyDir(
            'mount-io-profile',
            "<?php return ['mounts' => ['/home' => ['readBw' => '25M']]];\n"
        );

        $out = $this->pmssRunUserConfigCgroupCli(
            ['root', '--apply', '--dry-run', '--defaults', '--device=/dev/manual', '--io-profile=hdd'],
            ['PMSS_CONFIG_DIR' => $cfgDir, 'PMSS_HOME_DEVICE' => '/dev/testhome']
        );

        $this->assertStringContainsString('IOReadBandwidthMax=/dev/manual 5M', $out);
        $this->assertTrue(strpos($out, 'IOReadBandwidthMax=/dev/testhome 25M') === false);
    }
}
