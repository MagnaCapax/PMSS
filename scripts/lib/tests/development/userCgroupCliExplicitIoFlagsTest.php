<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserCgroupCliExplicitIoFlagsTest extends TestCase
{
    private function runCli(array $args): string
    {
        $cmd = 'php '.escapeshellarg(getcwd().'/scripts/util/userConfigCgroup.php').' '.implode(' ', array_map('escapeshellarg', $args));
        return (string)@shell_exec($cmd.' 2>&1');
    }

    public function testIoWriteBandwidthFlag(): void
    {
        $out = $this->runCli(['root', '--apply', '--dry-run', '--io-write-bw=/dev/sda:12M']);
        $this->assertStringContainsString('IOWriteBandwidthMax=/dev/sda 12M', $out);
    }

    public function testIoReadIopsFlag(): void
    {
        $out = $this->runCli(['root', '--apply', '--dry-run', '--io-read-iops=/dev/sda:77']);
        $this->assertStringContainsString('IOReadIOPSMax=/dev/sda 77', $out);
    }

    public function testIoWriteIopsFlag(): void
    {
        $out = $this->runCli(['root', '--apply', '--dry-run', '--io-write-iops=/dev/sda:88']);
        $this->assertStringContainsString('IOWriteIOPSMax=/dev/sda 88', $out);
    }

    public function testCombinedIoFlags(): void
    {
        $out = $this->runCli(['root', '--apply', '--dry-run', '--io-read-bw=/dev/sda:1M', '--io-write-iops=/dev/sda:9']);
        $this->assertStringContainsString('IOReadBandwidthMax=/dev/sda 1M', $out);
        $this->assertStringContainsString('IOWriteIOPSMax=/dev/sda 9', $out);
    }
}

