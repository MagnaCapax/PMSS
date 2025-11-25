<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class PolicyOverridePrecedenceTest extends TestCase
{
    private function runCli(array $args, array $env = []): string
    {
        $cmd = 'php '.escapeshellarg(getcwd().'/scripts/util/userConfigCgroup.php').' '.implode(' ', array_map('escapeshellarg', $args));
        $envExport = '';
        foreach ($env as $k=>$v) { $envExport .= $k.'='.escapeshellarg($v).' '; }
        return (string)@shell_exec($envExport.$cmd.' 2>&1');
    }

    public function testDefaultsApplyWhenExplicitMissing(): void
    {
        $cfgDir = sys_get_temp_dir().'/pmss-cg-'.bin2hex(random_bytes(4)).'-policy';
        @mkdir($cfgDir, 0700, true);
        file_put_contents($cfgDir.'/cgroup.policy.php', "<?php return ['cpuWeight'=>123,'ioWeight'=>321,'tasksMax'=>777];\n");
        $out = $this->runCli(['root', '--apply', '--dry-run', '--defaults'], [ 'PMSS_CONFIG_DIR' => $cfgDir ]);
        $this->assertStringContainsString('CPUWeight=123', $out);
        $this->assertStringContainsString('IOWeight=321', $out);
        $this->assertStringContainsString('TasksMax=777', $out);
    }

    public function testExplicitOverridesPolicyDefaults(): void
    {
        $cfgDir = sys_get_temp_dir().'/pmss-cg-'.bin2hex(random_bytes(4)).'-policy2';
        @mkdir($cfgDir, 0700, true);
        file_put_contents($cfgDir.'/cgroup.policy.php', "<?php return ['cpuWeight'=>111];\n");
        $out = $this->runCli(['root', '--apply', '--dry-run', '--defaults', '--cpu-weight=999'], [ 'PMSS_CONFIG_DIR' => $cfgDir ]);
        $this->assertStringContainsString('CPUWeight=999', $out);
    }
}

