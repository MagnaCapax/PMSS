<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserCgroupPolicyProfilesTest extends TestCase
{
    private function createPolicyDir(array $policy): string
    {
        $directory = sys_get_temp_dir().'/pmss-cgroup-policy-profiles-'.bin2hex(random_bytes(4));
        @mkdir($directory, 0700, true);

        $body = "<?php\nreturn ".var_export($policy, true).";\n";
        file_put_contents($directory.'/cgroup.policy.php', $body);

        return $directory;
    }

    private function runCli(array $args, array $env = []): string
    {
        $command = 'php '.escapeshellarg(getcwd().'/scripts/util/userConfigCgroup.php').' '
            .implode(' ', array_map('escapeshellarg', $args));

        $envExport = '';
        foreach ($env as $key => $value) {
            $envExport .= $key.'='.escapeshellarg($value).' ';
        }

        return (string) @shell_exec($envExport.$command.' 2>&1');
    }

    public function testCpuProfileUsesPolicyFamilyWhenDefined(): void
    {
        $configDirectory = $this->createPolicyDir([
            'profiles' => [
                'cpu' => ['balanced' => 180],
            ],
        ]);

        $output = $this->runCli(
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

        $output = $this->runCli(
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

        $output = $this->runCli(
            ['root', '--apply', '--dry-run', '--mem-profile=streaming'],
            ['PMSS_CONFIG_DIR' => $configDirectory]
        );

        $this->assertStringContainsString('MemoryHigh=1536M', $output);
        $this->assertStringContainsString('MemoryMax=', $output);
    }

    public function testBuiltInProfileStillWorksWithoutPolicyFamily(): void
    {
        $configDirectory = $this->createPolicyDir([]);

        $output = $this->runCli(
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

        $output = $this->runCli(
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

        $output = $this->runCli(
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
                    ],
                ],
            ],
        ]);

        $output = $this->runCli(
            ['root', '--apply', '--dry-run', '--device=/dev/sda', '--io-profile=archive'],
            ['PMSS_CONFIG_DIR' => $configDirectory]
        );

        $this->assertStringContainsString('IOWeight=150', $output);
        $this->assertStringContainsString('IOReadBandwidthMax=/dev/sda 3M', $output);
        $this->assertStringContainsString('IOWriteBandwidthMax=/dev/sda 4M', $output);
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

        $output = $this->runCli(
            ['root', '--apply', '--dry-run', '--device=/dev/sda', '--io-profile=bulk'],
            ['PMSS_CONFIG_DIR' => $configDirectory]
        );

        $this->assertStringContainsString('IOWeight=333', $output);
        $this->assertStringContainsString('CPUWeight=444', $output);
        $this->assertStringContainsString('TasksMax=9999', $output);
    }
}
