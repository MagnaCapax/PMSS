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
        $this->assertEquals("'/usr/bin/iostat' -xm 120 2 -g grp1 'sda' 'sdb' 2>&1", \pmssDiskIostatBuildCommand(['sda', 'sdb'], '/usr/bin/iostat'));
    }

    public function testBuildCommandKeepsNoDeviceFallbackForNvmeOnlyHosts(): void
    {
        $this->assertEquals("'/usr/bin/iostat' -xm 120 2 -g grp1 2>&1", \pmssDiskIostatBuildCommand([], '/usr/bin/iostat'));
    }

    public function testBuildCommandRejectsUnsafeDeviceNames(): void
    {
        $this->assertThrowsRuntime(static function (): void {
            \pmssDiskIostatBuildCommand(['sda;rm'], '/usr/bin/iostat');
        }, 'Unsafe block device name for iostat');
    }

    public function testParseLatestSampleUsesHeaderNames(): void
    {
        $raw = "Device r/s rMB/s rrqm/s %rrqm r_await rareq-sz w/s wMB/s wrqm/s %wrqm w_await wareq-sz d/s dMB/s drqm/s %drqm d_await dareq-sz f/s f_await aqu-sz %util\n"
            ."sda 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0\n"
            ."Device r/s rMB/s rrqm/s %rrqm r_await rareq-sz w/s wMB/s wrqm/s %wrqm w_await wareq-sz d/s dMB/s drqm/s %drqm d_await dareq-sz f/s f_await aqu-sz %util\n"
            ."grp1 1.10 2.20 0.00 0.00 12.50 128.00 3.30 4.40 0.00 0.00 34.25 256.00 0.00 0.00 0.00 0.00 0.00 0.00 0.10 0.20 1.25 88.80\n";

        $parsed = \pmssDiskIostatParseLatestSample($raw, 2, 123456);

        $this->assertSame([
            'iopsRead' => '1.10',
            'iopsWrite' => '3.30',
            'throughputRead' => '2.20',
            'throughputWrite' => '4.40',
            'diskAwait' => '12.50',
            'diskServiceTime' => '34.25',
            'diskUtil' => '88.80',
            'avgQueueSize' => '1.25',
            'diskQuantity' => 2,
            'time' => 123456,
        ], array_intersect_key($parsed, array_flip([
            'iopsRead', 'iopsWrite', 'throughputRead', 'throughputWrite', 'diskAwait',
            'diskServiceTime', 'diskUtil', 'avgQueueSize', 'diskQuantity', 'time',
        ])));
    }

    public function testParseLatestSampleKeepsLegacyAwaitFallbacks(): void
    {
        $raw = "Device r/s w/s rMB/s wMB/s avgqu-sz await svctm %util\n"
            ."grp1 5.00 6.00 7.00 8.00 0.50 9.90 10.10 11.10\n";

        $parsed = \pmssDiskIostatParseLatestSample($raw, 1, 123456);

        $this->assertSame([
            'diskAwait' => '9.90',
            'diskServiceTime' => '10.10',
            'avgQueueSize' => '0.50',
        ], array_intersect_key($parsed, array_flip(['diskAwait', 'diskServiceTime', 'avgQueueSize'])));
    }

    public function testParseLatestSampleReportsMissingHeader(): void
    {
        $this->assertThrowsRuntime(static function (): void {
            \pmssDiskIostatParseLatestSample("not iostat\n", 1, 123456);
        }, 'No iostat Device header found');
    }

    public function testWriteSnapshotFilesChecksEachOutput(): void
    {
        $root = $this->pmssMakeTempDir('pmss-iostat-write-');
        $path = $root.'/iostat';
        $historyPath = $root.'/iostat-history.log';
        $historyRawPath = $root.'/iostat-history-raw.log';
        $payload = ['iopsRead' => '1.00', 'diskQuantity' => 1, 'time' => 123456];

        $this->assertTrue(\pmssDiskIostatWriteSnapshotFiles($path, $payload, 'raw', $historyPath, $historyRawPath));
        $this->assertSame(serialize($payload), (string) file_get_contents($path));
        $this->assertStringContainsString(serialize($payload), (string) file_get_contents($historyPath));
        $this->assertStringContainsString("raw\n---\n", (string) file_get_contents($historyRawPath));

        $this->assertFalse(\pmssDiskIostatWriteSnapshotFiles($root.'/missing/iostat', $payload, 'raw'));
    }
}
