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
}
