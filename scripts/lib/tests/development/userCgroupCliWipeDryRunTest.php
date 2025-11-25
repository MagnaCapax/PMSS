<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserCgroupCliWipeDryRunTest extends TestCase
{
    private function runCli(array $args, array $env = []): string
    {
        $cmd = 'php '.escapeshellarg(getcwd().'/scripts/util/userConfigCgroup.php').' '.implode(' ', array_map('escapeshellarg', $args));
        $envExport = '';
        foreach ($env as $k=>$v) { $envExport .= $k.'='.escapeshellarg($v).' '; }
        return (string)@shell_exec($envExport.$cmd.' 2>&1');
    }

    public function testWipeIsNoopUnderDryRun(): void
    {
        $out = $this->runCli(['root', '--apply', '--dry-run', '--wipe']);
        $this->assertStringContainsString('(dry-run or no --apply; not changing system)', $out);
        $this->assertTrue(strpos($out, 'Reverting user slice') === false);
    }

    public function testDryRunPrintsPlannedWhenPropsPresent(): void
    {
        $out = $this->runCli(['root', '--apply', '--dry-run', '--cpu-weight=123', '--io-weight=321']);
        $this->assertStringContainsString('Planned properties', $out);
        $this->assertStringContainsString('CPUWeight=123', $out);
        $this->assertStringContainsString('IOWeight=321', $out);
    }
}

