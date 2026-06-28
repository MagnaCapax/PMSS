<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/UserConfigCgroupCliTrait.php';

class UserCgroupCliExplicitIoFlagsTest extends TestCase
{
    use UserConfigCgroupCliTrait;

    public function testIoWriteBandwidthFlag(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--io-write-bw=/dev/sda:12M']);
        $this->assertStringContainsString('IOWriteBandwidthMax=/dev/sda 12M', $out);
    }

    public function testIoReadIopsFlag(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--io-read-iops=/dev/sda:77']);
        $this->assertStringContainsString('IOReadIOPSMax=/dev/sda 77', $out);
    }

    public function testIoWriteIopsFlag(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--io-write-iops=/dev/sda:88']);
        $this->assertStringContainsString('IOWriteIOPSMax=/dev/sda 88', $out);
    }

    public function testCombinedIoFlags(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--io-read-bw=/dev/sda:1M', '--io-write-iops=/dev/sda:9']);
        $this->assertStringContainsString('IOReadBandwidthMax=/dev/sda 1M', $out);
        $this->assertStringContainsString('IOWriteIOPSMax=/dev/sda 9', $out);
    }

    public function testExplicitIoFlagsKeepCanonicalPropertyOrder(): void
    {
        $out = $this->pmssRunUserConfigCgroupCli(['root', '--apply', '--dry-run', '--io-write-iops=/dev/sdb:9', '--io-read-bw=/dev/sda:1M', '--io-write-bw=/dev/sda:2M', '--io-read-iops=/dev/sdb:8']);
        $this->assertStringContainsString("[Planned IO properties]\nIOReadBandwidthMax=/dev/sda 1M\nIOWriteBandwidthMax=/dev/sda 2M\nIOReadIOPSMax=/dev/sdb 8\nIOWriteIOPSMax=/dev/sdb 9\n", $out);
    }
}
