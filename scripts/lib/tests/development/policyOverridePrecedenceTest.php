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
        $this->pmssAssertRepoPhpScriptOutputContains('scripts/util/userConfigCgroup.php', ['root', '--apply', '--dry-run', '--defaults'], ['CPUWeight=123', 'IOWeight=321', 'TasksMax=777'], ['PMSS_CONFIG_DIR' => $cfgDir]);
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

        $this->pmssAssertRepoPhpScriptOutputContains(
            'scripts/util/userConfigCgroup.php',
            ['root', '--apply', '--dry-run', '--defaults'],
            ['IODeviceWeight=/dev/testhome 320', 'IOReadBandwidthMax=/dev/testhome 25M', 'IOWriteBandwidthMax=/dev/testhome 10M', 'IOReadIOPSMax=/dev/testhome 150', 'IOWriteIOPSMax=/dev/testhome 90'],
            ['PMSS_CONFIG_DIR' => $cfgDir, 'PMSS_HOME_DEVICE' => '/dev/testhome']
        );
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
        $this->assertStringNotContainsString('IOReadBandwidthMax=/dev/testhome 25M', $out);
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
        $this->assertStringNotContainsString('IOReadBandwidthMax=/dev/testhome 25M', $out);
    }
}
