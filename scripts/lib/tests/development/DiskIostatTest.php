<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/diskIostat.php';

class DiskIostatTest extends TestCase
{
    public function testDiscoverDevicesMatchesLegacySdFilterAndRejectsUnsafeNames(): void
    {
        $sysBlock = $this->pmssMakeTempDir('pmss-sys-block-');
        foreach (['sda', 'sdb', 'nvme0n1', 'loop0', 'md0', 'sd;bad'] as $entry) {
            $this->pmssEnsureDir($sysBlock.'/'.$entry, 0755);
        }

        $this->assertEquals(['sda', 'sdb'], \pmssDiskIostatDiscoverDevices($sysBlock));
    }

    public function testBuildCommandShellEscapesValidatedDevices(): void
    {
        $command = \pmssDiskIostatBuildCommand(['sda', 'sdb'], '/usr/bin/iostat');

        $this->assertEquals("'/usr/bin/iostat' -xm 120 2 -g grp1 'sda' 'sdb' 2>&1", $command);
    }

    public function testBuildCommandKeepsNoDeviceFallbackForNvmeOnlyHosts(): void
    {
        $command = \pmssDiskIostatBuildCommand([], '/usr/bin/iostat');

        $this->assertEquals("'/usr/bin/iostat' -xm 120 2 -g grp1 2>&1", $command);
    }

    public function testBuildCommandRejectsUnsafeDeviceNames(): void
    {
        try {
            \pmssDiskIostatBuildCommand(['sda;rm'], '/usr/bin/iostat');
            $this->fail('Expected unsafe device name to throw');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Unsafe block device name for iostat', $exception->getMessage());
        }
    }

    public function testParseLatestSampleUsesHeaderNames(): void
    {
        $raw = "Device r/s rMB/s rrqm/s %rrqm r_await rareq-sz w/s wMB/s wrqm/s %wrqm w_await wareq-sz d/s dMB/s drqm/s %drqm d_await dareq-sz f/s f_await aqu-sz %util\n"
            ."sda 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0\n"
            ."Device r/s rMB/s rrqm/s %rrqm r_await rareq-sz w/s wMB/s wrqm/s %wrqm w_await wareq-sz d/s dMB/s drqm/s %drqm d_await dareq-sz f/s f_await aqu-sz %util\n"
            ."grp1 1.10 2.20 0.00 0.00 12.50 128.00 3.30 4.40 0.00 0.00 34.25 256.00 0.00 0.00 0.00 0.00 0.00 0.00 0.10 0.20 1.25 88.80\n";

        $parsed = \pmssDiskIostatParseLatestSample($raw, 2, 123456);

        $this->assertSame('1.10', $parsed['iopsRead']);
        $this->assertSame('3.30', $parsed['iopsWrite']);
        $this->assertSame('2.20', $parsed['throughputRead']);
        $this->assertSame('4.40', $parsed['throughputWrite']);
        $this->assertSame('12.50', $parsed['diskAwait']);
        $this->assertSame('34.25', $parsed['diskServiceTime']);
        $this->assertSame('88.80', $parsed['diskUtil']);
        $this->assertSame('1.25', $parsed['avgQueueSize']);
        $this->assertSame(2, $parsed['diskQuantity']);
        $this->assertSame(123456, $parsed['time']);
    }

    public function testParseLatestSampleKeepsLegacyAwaitFallbacks(): void
    {
        $raw = "Device r/s w/s rMB/s wMB/s avgqu-sz await svctm %util\n"
            ."grp1 5.00 6.00 7.00 8.00 0.50 9.90 10.10 11.10\n";

        $parsed = \pmssDiskIostatParseLatestSample($raw, 1, 123456);

        $this->assertSame('9.90', $parsed['diskAwait']);
        $this->assertSame('10.10', $parsed['diskServiceTime']);
        $this->assertSame('0.50', $parsed['avgQueueSize']);
    }

    public function testParseLatestSampleReportsMissingHeader(): void
    {
        try {
            \pmssDiskIostatParseLatestSample("not iostat\n", 1, 123456);
            $this->fail('Expected missing header to throw');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('No iostat Device header found', $exception->getMessage());
        }
    }

    public function testWriteSnapshotFilesChecksEachOutput(): void
    {
        $root = $this->pmssMakeTempDir('pmss-iostat-write-');
        $path = $root.'/iostat';
        $payload = ['iopsRead' => '1.00', 'diskQuantity' => 1, 'time' => 123456];

        $this->assertTrue(\pmssDiskIostatWriteSnapshotFiles($path, $payload, 'raw'));
        $this->assertSame(serialize($payload), (string) file_get_contents($path));
        $this->assertStringContainsString(serialize($payload), (string) file_get_contents($path.'-history'));
        $this->assertStringContainsString("raw\n---\n", (string) file_get_contents($path.'-history-raw'));

        $this->assertFalse(\pmssDiskIostatWriteSnapshotFiles($root.'/missing/iostat', $payload, 'raw'));
    }
}
