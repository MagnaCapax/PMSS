<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserCgroupCliTasksAndCpuProfilesTest extends TestCase
{
    private function runCli(array $args): string
    {
        $cmd = 'php '.escapeshellarg(getcwd().'/scripts/util/userCgroup.php').' '.implode(' ', array_map('escapeshellarg', $args));
        return (string)@shell_exec($cmd.' 2>&1');
    }

    public function testTasksProfileLow(): void
    {
        $out = $this->runCli(['root', '--apply', '--dry-run', '--tasks-profile=low']);
        $this->assertStringContainsString('TasksMax=1024', $out);
    }

    public function testTasksProfileHigh(): void
    {
        $out = $this->runCli(['root', '--apply', '--dry-run', '--tasks-profile=high']);
        $this->assertStringContainsString('TasksMax=8192', $out);
    }

    public function testCpuProfileLow(): void
    {
        $out = $this->runCli(['root', '--apply', '--dry-run', '--cpu-profile=low']);
        $this->assertStringContainsString('CPUWeight=50', $out);
    }

    public function testCpuProfileHigh(): void
    {
        $out = $this->runCli(['root', '--apply', '--dry-run', '--cpu-profile=high']);
        $this->assertStringContainsString('CPUWeight=300', $out);
    }

    public function testMemProfileDefault(): void
    {
        $out = $this->runCli(['root', '--apply', '--dry-run', '--mem-profile=default']);
        $this->assertStringContainsString('MemoryHigh=500M', $out);
    }
}

