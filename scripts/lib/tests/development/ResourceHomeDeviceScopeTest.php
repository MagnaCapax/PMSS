<?php
/** Monthly IOPS budget counts only the /home device and the disks under it (#1297). */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/metrics.php';

class ResourceHomeDeviceScopeTest extends TestCase
{
    /** Fake class/block tree: md1 (/home) over sda4+sdb4, md0 (system) over sda2; kernel-style partition links. */
    private function mdTree(): string
    {
        $root = $this->pmssMakeTempDir('pmss-home-scope-');
        $class = $root.'/class/block';
        $devices = $root.'/devices';
        $this->pmssWriteFiles($devices, [
            'md1/dev' => "9:1\n", 'md0/dev' => "9:0\n", 'sda/dev' => "8:0\n", 'sdb/dev' => "8:16\n",
            'sda/sda4/partition' => '4', 'sda/sda4/dev' => "8:4\n", 'sdb/sdb4/partition' => '4', 'sdb/sdb4/dev' => "8:20\n",
        ]);
        $this->pmssWriteSymlinks($class, ['md1' => $devices.'/md1', 'md0' => $devices.'/md0', 'sda' => $devices.'/sda',
            'sdb' => $devices.'/sdb', 'sda4' => $devices.'/sda/sda4', 'sdb4' => $devices.'/sdb/sdb4']);
        $this->pmssWriteSymlinks($devices, ['md1/slaves/sda4' => $devices.'/sda/sda4', 'md1/slaves/sdb4' => $devices.'/sdb/sdb4']);
        return $class;
    }

    private function keys(string $source, string $class): ?array
    {
        return \pmssResourceLogHomeDeviceKeys(self::pmssMakeCallbacks(['mountRunner' => $source])['mountRunner'], $class);
    }

    public function testMdHomeKeepsArrayAndWholeMemberDisksOnly(): void
    {
        $class = $this->mdTree();
        // md1 carries throttle rows (the cap device); sda/sdb carry BFQ rows. Partitions and md0 stay out.
        $this->assertSame(['9:1' => true, '8:0' => true, '8:16' => true], $this->keys('/dev/md1', $class));
    }

    public function testGuestWholeDiskHomeIsOneKey(): void
    {
        $root = $this->pmssMakeTempDir('pmss-home-scope-');
        $this->pmssWriteFiles($root.'/devices', ['vdb/dev' => "252:16\n", 'vda/dev' => "252:0\n"]);
        $this->pmssWriteSymlinks($root.'/class/block', ['vdb' => $root.'/devices/vdb', 'vda' => $root.'/devices/vda']);
        $this->assertSame(['252:16' => true], $this->keys('/dev/vdb', $root.'/class/block'));
    }

    public function testUnresolvedHomeKeepsTheAllDeviceSum(): void
    {
        $class = $this->mdTree();
        foreach (['', 'tmpfs', '/srv/home', '/dev/', '/dev/../etc', '/dev/md9'] as $source) {
            $this->assertSame(null, $this->keys($source, $class), var_export($source, true));
        }
        // A member disk without a readable major:minor is unknown topology, not "no member".
        @unlink(dirname($class, 2).'/devices/sdb/dev');
        $this->assertSame(null, $this->keys('/dev/md1', $class));
    }

    public function testReaderCountsOnlyListedDevicesAndNormalisesKeys(): void
    {
        $path = $this->pmssMakeTempDir('pmss-home-scope-', 0700).'/blkio';
        $this->pmssWriteFile($path, "8:0 Read 5\n008:016 Read 7\n9:0 Read 1000\n9:1 Write 3\n8:32 Write 900\nTotal 1915\n");
        $home = ['9:1' => true, '8:0' => true, '8:16' => true];
        $this->assertSame(['read' => 12, 'write' => 3], \pmssResourceLogReadBlkioReadWrite($path, $home));
        $this->assertSame(['read' => 1012, 'write' => 903], \pmssResourceLogReadBlkioReadWrite($path));
        $this->assertSame(null, \pmssResourceLogReadBlkioReadWrite($path, ['8:48' => true]));
    }

    public function testScopeTravelsWithTheSampleAndTelemetryDropsIt(): void
    {
        $slice = 'blkio/user.slice/user-1000.slice/';
        $root = $this->pmssWriteFiles($this->pmssMakeTempDir('pmss-home-scope-', 0700), [
            $slice.'blkio.bfq.io_service_bytes_recursive' => "8:0 Read 4096\n8:16 Read 4096\n8:48 Read 999999\n",
            $slice.'blkio.bfq.io_serviced_recursive' => "8:0 Read 1\n8:16 Read 1\n8:48 Read 250\n8:0 Write 0\n",
            $slice.'blkio.throttle.io_service_bytes_recursive' => "9:0 Write 512\n9:1 Write 1024\n",
            $slice.'blkio.throttle.io_serviced_recursive' => "9:0 Write 9\n9:1 Write 2\n",
        ]);
        $home = ['9:1' => true, '8:0' => true, '8:16' => true];
        $scoped = \pmssResourceV1ReadMetrics(1000, $root, [], $home);
        $this->assertSame([8192, 1024, 2, 2, 'bfq-read-throttle-write', 'home-recursive'],
            [$scoped['io_bytes_read'], $scoped['io_bytes_write'], $scoped['io_ops_read'], $scoped['io_ops_write'], $scoped['io_source'], $scoped['io_scope']]);
        $all = \pmssResourceV1ReadMetrics(1000, $root, []);
        $this->assertSame([252, 11, 'recursive'], [$all['io_ops_read'], $all['io_ops_write'], $all['io_scope']]);
        // Billing keeps the scope so a scope change reseeds; an injected fixture root keeps the all-device sum.
        $this->assertSame('recursive', \pmssResourceLogReadCountersV1(1000, $root)['io_scope'] ?? null);
        $this->assertSame(false, array_key_exists('io_scope', \pmssUserMetricsCollect(1000, $root)));
    }
}
