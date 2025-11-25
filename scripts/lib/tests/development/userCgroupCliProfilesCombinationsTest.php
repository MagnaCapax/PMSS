<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserCgroupCliProfilesCombinationsTest extends TestCase
{
    private function runCli(array $args, array $env = []): string
    {
        $cmd = 'php '.escapeshellarg(getcwd().'/scripts/util/userConfigCgroup.php').' '.implode(' ', array_map('escapeshellarg', $args));
        $envExport = '';
        foreach ($env as $k=>$v) { $envExport .= $k.'='.escapeshellarg($v).' '; }
        return (string)@shell_exec($envExport.$cmd.' 2>&1');
    }

    public function testNvmeProfileNoIoLimits(): void
    {
        $out = $this->runCli(['root', '--apply', '--dry-run', '--device=/home', '--io-profile=nvme'], [
            'PMSS_HOME_DEVICE' => '/dev/pmssNVME',
        ]);
        $this->assertStringContainsString('IOWeight=200', $out);
        $this->assertTrue(strpos($out, 'IOReadBandwidthMax=') === false);
    }

    public function testBulkProfileRaisesWeights(): void
    {
        $out = $this->runCli(['root', '--apply', '--dry-run', '--device=/home', '--io-profile=bulk'], [
            'PMSS_HOME_DEVICE' => '/dev/pmssBULK',
        ]);
        $this->assertStringContainsString('IOWeight=500', $out);
        $this->assertStringContainsString('CPUWeight=300', $out);
        $this->assertStringContainsString('TasksMax=8192', $out);
    }

    public function testExplicitIoWeightOverridesProfile(): void
    {
        $out = $this->runCli(['root', '--apply', '--dry-run', '--device=/home', '--io-profile=bulk', '--io-weight=777'], [
            'PMSS_HOME_DEVICE' => '/dev/pmssBULK',
        ]);
        $this->assertStringContainsString('IOWeight=777', $out);
    }

    public function testInvalidDevicePathSkipsIoLines(): void
    {
        $out = $this->runCli(['root', '--apply', '--dry-run', '--device=/nope', '--io-profile=hdd']);
        $this->assertTrue(strpos($out, 'IOReadBandwidthMax=') === false);
    }

    public function testExplicitIoReadBwFlagProducesIoLine(): void
    {
        $out = $this->runCli(['root', '--apply', '--dry-run', '--io-read-bw=/dev/sda:3M']);
        $this->assertStringContainsString('IOReadBandwidthMax=/dev/sda 3M', $out);
    }
}

