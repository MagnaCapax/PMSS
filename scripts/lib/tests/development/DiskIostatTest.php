<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/diskIostat.php';

class DiskIostatTest extends TestCase
{
    /** Build a fake class/block tree with kernel-style partition links. */
    private function homeTree(array $chains): array
    {
        $root = $this->pmssMakeTempDir('pmss-iostat-home-');
        $class = $root.'/class/block';
        $devices = $root.'/devices';
        $this->pmssEnsureDir($class, 0755);
        foreach ($chains as $parent => $children) {
            $this->pmssEnsureDir($devices.'/'.$parent.'/slaves', 0755);
            @symlink($devices.'/'.$parent, $class.'/'.$parent);
            foreach ($children as $child) {
                $this->pmssEnsureDir($devices.'/'.$child, 0755);
                @symlink($devices.'/'.$child, $class.'/'.$child);
                @symlink($devices.'/'.$child, $devices.'/'.$parent.'/slaves/'.$child);
            }
        }
        return [$root, $class, $devices];
    }

    /** Compact last-sample fixture; old group fields deliberately differ from leaves. */
    private function homeSample(array $rows): string
    {
        $header = "Device r/s w/s r_await w_await %util\n";
        $raw = $header."grp1 1 2 90 80 70\n".$header;
        foreach ($rows as $device => $values) $raw .= $device.' '.implode(' ', $values)."\n";
        return $raw."grp1 1 2 90 80 70\n";
    }

    /** Resolve a fake /home source without invoking findmnt. */
    private function homeDevices(array $sampled, string $source, string $class, string $devRoot): ?array
    {
        return \pmssDiskIostatHomeDevices($sampled, $class, static function () use ($source): string { return $source; }, $devRoot);
    }

    public function testGuestHomeFieldsKeepGroupFieldsAndSerializedLegacyValues(): void
    {
        list($root, $class) = $this->homeTree(['vda' => [], 'vdb' => []]);
        $leaves = $this->homeDevices(['vda', 'vdb'], $root.'/dev/vda', $class, $root.'/dev');
        $parsed = \pmssDiskIostatParseLatestSample($this->homeSample([
            'vda' => [4, 5, 12, 34, 99], 'vdb' => [100, 100, 1, 2, 3],
        ]), 2, 123, $leaves);
        $this->assertSame(['homeDiskAwait' => 12.0, 'homeDiskServiceTime' => 34.0, 'homeDiskQuantity' => 1], array_intersect_key($parsed, array_flip(['homeDiskAwait', 'homeDiskServiceTime', 'homeDiskQuantity'])));
        $stored = unserialize(serialize($parsed));
        $this->assertSame(['iopsRead' => '1', 'iopsWrite' => '2', 'throughputRead' => '0', 'throughputWrite' => '0',
            'diskAwait' => '90', 'diskServiceTime' => '80', 'diskUtil' => '70', 'avgQueueSize' => '0',
            'diskQuantity' => 2, 'time' => 123], array_intersect_key($stored, array_flip([
            'iopsRead', 'iopsWrite', 'throughputRead', 'throughputWrite', 'diskAwait', 'diskServiceTime',
            'diskUtil', 'avgQueueSize', 'diskQuantity', 'time',
        ])));
        foreach (['psiFullAvg300', 'psiMemFullAvg300', 'psiCpuFullAvg300', 'iopingHomeMs'] as $key) $this->assertTrue(array_key_exists($key, $stored));
    }

    public function testMdRaidWeightsOnlySixMemberDisks(): void
    {
        list($root, $class, $devices) = $this->homeTree(['md0' => []]);
        $rows = [];
        $sampled = [];
        foreach (range('a', 'f') as $index => $letter) {
            $disk = 'sd'.$letter;
            $part = $disk.'1';
            $this->pmssEnsureDir($devices.'/'.$disk.'/'.$part, 0755);
            file_put_contents($devices.'/'.$disk.'/'.$part.'/partition', '1');
            @symlink($devices.'/'.$disk, $class.'/'.$disk);
            @symlink($devices.'/'.$disk.'/'.$part, $class.'/'.$part);
            @symlink($devices.'/'.$disk.'/'.$part, $devices.'/md0/slaves/'.$part);
            $sampled[] = $disk;
            $rows[$disk] = [$index + 1, 6 - $index, 10 * ($index + 1), 10 * ($index + 1), 50];
        }
        $sampled[] = 'nvme0n1';
        $rows['nvme0n1'] = [1000, 1000, 1, 1, 1];
        $leaves = $this->homeDevices($sampled, $root.'/dev/md0', $class, $root.'/dev');
        $this->assertSame($sampled === [] ? [] : array_slice($sampled, 0, 6), $leaves);
        $parsed = \pmssDiskIostatParseLatestSample($this->homeSample($rows), 7, 123, $leaves);
        $this->assertSame(6, $parsed['homeDiskQuantity']);
        $this->assertSame(910 / 21, $parsed['homeDiskAwait']);
        $this->assertSame(560 / 21, $parsed['homeDiskServiceTime']);
        $this->assertSame(7, $parsed['diskQuantity']);
    }

    public function testBcacheThroughMdAndMapperResolveLeaves(): void
    {
        list($root, $class, $devices) = $this->homeTree(['bcache0' => ['md1'], 'md1' => []]);
        $this->pmssEnsureDir($devices.'/sda/sda1', 0755);
        file_put_contents($devices.'/sda/sda1/partition', '1');
        @symlink($devices.'/sda', $class.'/sda');
        @symlink($devices.'/sda/sda1', $class.'/sda1');
        @symlink($devices.'/sda/sda1', $devices.'/md1/slaves/sda1');
        $this->assertSame(['sda'], $this->homeDevices(['sda'], $root.'/dev/bcache0', $class, $root.'/dev'));

        $this->pmssEnsureDir($devices.'/dm-0/slaves', 0755);
        $this->pmssEnsureDir($devices.'/sdb/sdb2', 0755);
        file_put_contents($devices.'/sdb/sdb2/partition', '2');
        @symlink($devices.'/dm-0', $class.'/dm-0');
        @symlink($devices.'/sdb', $class.'/sdb');
        @symlink($devices.'/sdb/sdb2', $class.'/sdb2');
        @symlink($devices.'/sdb/sdb2', $devices.'/dm-0/slaves/sdb2');
        $this->pmssEnsureDir($root.'/dev/mapper', 0755);
        @symlink($root.'/dev/dm-0', $root.'/dev/mapper/vg-home');
        file_put_contents($root.'/dev/dm-0', '');
        $this->assertSame(['sdb'], $this->homeDevices(['sdb'], $root.'/dev/mapper/vg-home', $class, $root.'/dev'));
    }

    public function testUnknownHomeAndMissingRowsStayNull(): void
    {
        list($root, $class) = $this->homeTree(['vda' => []]);
        $raw = $this->homeSample(['vda' => [1, 1, 5, 6, 7]]);
        foreach ([null, $this->homeDevices(['vdb'], $root.'/dev/vda', $class, $root.'/dev'), ['sda']] as $leaves) {
            $parsed = \pmssDiskIostatParseLatestSample($raw, 2, 123, $leaves);
            $this->assertSame(null, $parsed['homeDiskAwait']);
            $this->assertSame(null, $parsed['homeDiskServiceTime']);
            $this->assertSame(null, $parsed['homeDiskQuantity']);
            $this->assertSame('90', $parsed['diskAwait']);
            $this->assertSame(2, $parsed['diskQuantity']);
        }
        $this->assertSame(null, $this->homeDevices(['vda'], '', $class, $root.'/dev'));
    }

    public function testZeroTrafficUsesPlainMean(): void
    {
        $raw = $this->homeSample(['sda' => [0, 0, 10, 20, 1], 'sdb' => [0, 0, 30, 40, 1]]);
        $parsed = \pmssDiskIostatParseLatestSample($raw, 2, 123, ['sda', 'sdb']);
        $this->assertSame(20.0, $parsed['homeDiskAwait']);
        $this->assertSame(30.0, $parsed['homeDiskServiceTime']);
    }
    public function testDiscoverDevicesMatchesSharedDataDeviceFilter(): void
    {
        $sysBlock = $this->pmssMakeTempDir('pmss-sys-block-');
        foreach (['sda', 'sdb', 'vda', 'xvda', 'nvme0n1', 'mmcblk0', 'sda1', 'nvme0n1p1', 'mmcblk0p1', 'loop0', 'md0', 'sd;bad'] as $entry) {
            $this->pmssEnsureDir($sysBlock.'/'.$entry, 0755);
        }

        $this->assertEquals(['mmcblk0', 'nvme0n1', 'sda', 'sdb', 'vda', 'xvda'], \pmssDiskIostatDiscoverDevices($sysBlock));
    }

    public function testBuildCommandShellEscapesValidatedDevices(): void
    {
        $this->assertEquals("'/usr/bin/iostat' -xm 120 2 -g grp1 'sda' 'sdb' 2>&1", \pmssDiskIostatBuildCommand(['sda', 'sdb'], '/usr/bin/iostat'));
    }

    public function testBuildCommandKeepsNoDeviceFallbackForEmptyDiscovery(): void
    {
        $this->assertEquals("'/usr/bin/iostat' -xm 120 2 -g grp1 2>&1", \pmssDiskIostatBuildCommand([], '/usr/bin/iostat'));
    }

    public function testBuildCommandRejectsUnsafeDeviceNames(): void
    {
        $this->assertThrowsRuntime(static function (): void {
            \pmssDiskIostatBuildCommand(['sda;rm'], '/usr/bin/iostat');
        }, 'Unsafe block device name for iostat');
    }

    public function testIostatCaptureUsesBoundedSharedCommandWrapper(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'scripts/lib/diskIostat.php',
            [
                'const PMSS_DISK_IOSTAT_TIMEOUT_SECONDS = 180;',
                '$result = pmssCommandCapture($command, PMSS_DISK_IOSTAT_TIMEOUT_SECONDS);',
                '$iostatRaw = (string) ($result[\'stdout\'] ?? \'\');',
            ],
            ['@shell'.'_exec($command)']
        );
    }

    public function testCronEntryPointUsesNonBlockingSingleInstanceLock(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/cron/diskIostat.php', [
            "\$pmssDiskIostatLock = pmssCronLockAcquire('diskIostat', 'pmssCronLockSkipLog')",
        ]);
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

    public function testDiscoveryRejectsMalformedPathsWithoutPhpWarnings(): void
    {
        $root = $this->pmssMakeTempDir('pmss-iostat-path-');
        $this->pmssEnsureDir($root.'/sda', 0755);
        foreach (['', "\0", "\0".$root, $root."\0", $root."\0/child"] as $path) {
            $this->pmssAssertNoPhpWarnings(function () use ($path): void {
                $this->assertSame([], \pmssDiskIostatDiscoverDevices($path));
            });
        }
        $this->assertSame(['sda'], \pmssDiskIostatDiscoverDevices($root));
    }

    public function testDeviceValidationRejectsTrailingAndEmbeddedControlBytes(): void
    {
        foreach (["sda\n", "\nsda", "sd\na", "sda\r", "sda\0", "sda\t", ''] as $device) {
            $this->assertFalse(\pmssDiskIostatDeviceNameIsSafe($device));
            $this->assertThrowsRuntime(static function () use ($device): void {
                \pmssDiskIostatBuildCommand([$device], '/usr/bin/iostat');
            }, 'Unsafe block device name for iostat');
        }
        foreach (['sda', 'nvme0n1', 'dm-0', 'disk.name', 'disk_name', 'disk+name'] as $device) {
            $this->assertTrue(\pmssDiskIostatDeviceNameIsSafe($device));
        }
    }

    public function testCommandRejectsNulExecutablePathsBeforeShellQuoting(): void
    {
        foreach (["\0", "\0iostat", "io\0stat", "iostat\0", "/usr/bin/iostat\0ignored"] as $binary) {
            $this->assertThrowsRuntime(static function () use ($binary): void {
                \pmssDiskIostatBuildCommand(['sda'], $binary);
            }, 'Unsafe iostat binary path');
        }
        // Quoting remains byte-compatible even for paths with shell punctuation.
        $this->assertSame("'/tmp/io stat' -xm 120 2 -g grp1 'sda' 2>&1", \pmssDiskIostatBuildCommand(['sda'], '/tmp/io stat'));
    }
}
