<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/UserConfigCgroupCliTrait.php';

class UserCgroupPolicyProfilesTest extends TestCase
{
    use UserConfigCgroupCliTrait;

    private function createPolicyDir(array $policy): string
    {
        $directory = $this->pmssMakeNamedTempDir('pmss-cgroup-policy-profiles-', 0700);

        $body = "<?php\nreturn ".var_export($policy, true).";\n";
        file_put_contents($directory.'/cgroup.policy.php', $body);

        return $directory;
    }
    public function testCpuProfileUsesPolicyFamilyWhenDefined(): void
    {
        $configDirectory = $this->createPolicyDir([
            'profiles' => [
                'cpu' => ['balanced' => 180],
            ],
        ]);

        $output = $this->pmssRunUserConfigCgroupCli(
            ['root', '--apply', '--dry-run', '--cpu-profile=balanced'],
            ['PMSS_CONFIG_DIR' => $configDirectory]
        );

        $this->assertStringContainsString('CPUWeight=180', $output);
    }

    public function testTasksProfileUsesPolicyFamilyWhenDefined(): void
    {
        $configDirectory = $this->createPolicyDir([
            'profiles' => [
                'tasks' => ['service' => 12000],
            ],
        ]);

        $output = $this->pmssRunUserConfigCgroupCli(
            ['root', '--apply', '--dry-run', '--tasks-profile=service'],
            ['PMSS_CONFIG_DIR' => $configDirectory]
        );

        $this->assertStringContainsString('TasksMax=12000', $output);
    }

    public function testMemProfileUsesPolicyFamilyWhenDefined(): void
    {
        $configDirectory = $this->createPolicyDir([
            'profiles' => [
                'mem' => ['streaming' => 1536],
            ],
        ]);

        $output = $this->pmssRunUserConfigCgroupCli(
            ['root', '--apply', '--dry-run', '--mem-profile=streaming'],
            ['PMSS_CONFIG_DIR' => $configDirectory]
        );

        $this->assertStringContainsString('MemoryHigh=1536M', $output);
        $this->assertStringContainsString('MemoryMax=', $output);
    }

    public function testBuiltInProfileStillWorksWithoutPolicyFamily(): void
    {
        $configDirectory = $this->createPolicyDir([]);

        $output = $this->pmssRunUserConfigCgroupCli(
            ['root', '--apply', '--dry-run', '--cpu-profile=low'],
            ['PMSS_CONFIG_DIR' => $configDirectory]
        );

        $this->assertStringContainsString('CPUWeight=50', $output);
    }

    public function testInvalidPolicyProfileValueFallsBackToBuiltIn(): void
    {
        $configDirectory = $this->createPolicyDir([
            'profiles' => [
                'cpu' => ['low' => 'invalid'],
            ],
        ]);

        $output = $this->pmssRunUserConfigCgroupCli(
            ['root', '--apply', '--dry-run', '--cpu-profile=low'],
            ['PMSS_CONFIG_DIR' => $configDirectory]
        );

        $this->assertStringContainsString('CPUWeight=50', $output);
    }

    public function testIoProfileUsesPolicyFamilyWhenDefined(): void
    {
        $configDirectory = $this->createPolicyDir([
            'profiles' => [
                'io' => [
                    'hdd' => [
                        'ioWeight' => 240,
                        'readBw' => '7M',
                        'writeBw' => '13M',
                        'readIops' => 77,
                        'writeIops' => 88,
                    ],
                ],
            ],
        ]);

        $output = $this->pmssRunUserConfigCgroupCli(
            ['root', '--apply', '--dry-run', '--device=/dev/sda', '--io-profile=hdd'],
            ['PMSS_CONFIG_DIR' => $configDirectory]
        );

        $this->assertStringContainsString('IOWeight=240', $output);
        $this->assertStringContainsString('IOReadBandwidthMax=/dev/sda 7M', $output);
        $this->assertStringContainsString('IOWriteBandwidthMax=/dev/sda 13M', $output);
        $this->assertStringContainsString('IOReadIOPSMax=/dev/sda 77', $output);
        $this->assertStringContainsString('IOWriteIOPSMax=/dev/sda 88', $output);
    }

    public function testIoProfilePolicyCanDefineCustomProfile(): void
    {
        $configDirectory = $this->createPolicyDir([
            'profiles' => [
                'io' => [
                    'archive' => [
                        'ioWeight' => 150,
                        'readBw' => '3M',
                        'writeBw' => '4M',
                        'readIops' => 30,
                        'writeIops' => 40,
                    ],
                ],
            ],
        ]);

        $output = $this->pmssRunUserConfigCgroupCli(
            ['root', '--apply', '--dry-run', '--device=/dev/sda', '--io-profile=archive'],
            ['PMSS_CONFIG_DIR' => $configDirectory]
        );

        $this->assertStringContainsAllStrings(['IOWeight=150', 'IOReadBandwidthMax=/dev/sda 3M', 'IOWriteBandwidthMax=/dev/sda 4M', 'IOReadIOPSMax=/dev/sda 30', 'IOWriteIOPSMax=/dev/sda 40'], $output);
    }

    public function testIoProfilePolicyCanOverrideBulkCpuAndTasksDefaults(): void
    {
        $configDirectory = $this->createPolicyDir([
            'profiles' => [
                'io' => [
                    'bulk' => [
                        'ioWeight' => 333,
                        'cpuWeight' => 444,
                        'tasksMax' => 9999,
                    ],
                ],
            ],
        ]);

        $output = $this->pmssRunUserConfigCgroupCli(
            ['root', '--apply', '--dry-run', '--device=/dev/sda', '--io-profile=bulk'],
            ['PMSS_CONFIG_DIR' => $configDirectory]
        );

        $this->assertStringContainsString('IOWeight=333', $output);
        $this->assertStringContainsString('CPUWeight=444', $output);
        $this->assertStringContainsString('TasksMax=9999', $output);
    }
}
