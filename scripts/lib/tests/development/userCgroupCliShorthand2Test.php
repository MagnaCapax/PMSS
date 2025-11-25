<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserCgroupCliShorthand2Test extends TestCase
{
    private function runCli(array $args, array $env = []): string
    {
        $cmd = 'php '.escapeshellarg(getcwd().'/scripts/util/userConfigCgroup.php').' '.implode(' ', array_map('escapeshellarg', $args));
        $envExport = '';
        foreach ($env as $k=>$v) { $envExport .= $k.'='.escapeshellarg($v).' '; }
        return (string)@shell_exec($envExport.$cmd.' 2>&1');
    }

    public function testDeviceHomeResolutionPlannedIo(): void
    {
        $out = $this->runCli(['root', '--apply', '--dry-run', '--device=/home', '--io-profile=hdd'], [
            'PMSS_HOME_DEVICE' => '/dev/pmssHOME',
        ]);
        $this->assertStringContainsString('user=root', $out);
        $this->assertStringContainsString('Planned IO properties', $out);
        $this->assertStringContainsString('IOReadBandwidthMax=/dev/pmssHOME 5M', $out);
        $this->assertStringContainsString('IOWriteIOPSMax=/dev/pmssHOME 100', $out);
    }

    public function testSingleCpuWeightFlagOnly(): void
    {
        $out = $this->runCli(['root', '--apply', '--dry-run', '--cpu-weight=300']);
        $this->assertStringContainsString('Planned properties', $out);
        $this->assertStringContainsString('CPUWeight=300', $out);
        // Memory should not be planned unless explicitly provided
        $this->assertTrue(strpos($out, 'MemoryHigh=') === false);
        $this->assertTrue(strpos($out, 'MemoryMax=') === false);
    }

    public function testMemProfileHeavySetsHigh(): void
    {
        $out = $this->runCli(['root', '--apply', '--dry-run', '--mem-profile=heavy']);
        $this->assertStringContainsString('Planned properties', $out);
        $this->assertStringContainsString('MemoryHigh=1024M', $out);
    }
}

