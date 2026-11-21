<?php
/** Verify cgroup-v1 counter row rejection before whitespace normalization. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/metrics.php';

class ResourceV1CounterInputSafetyTest extends TestCase
{
    /** Exercise scripts/lib/resources/v1.php with private files, never host cgroups. */
    public function testBlkioRejectsCorruptRowsAndKeepsValidNeighbors(): void
    {
        $path = $this->pmssMakeTempDir('pmss-v1-counter-', 0700).'/blkio';
        foreach ([
            "\0%s", "%s\0", " \0%s\t", "%s\0 \t", "8:\000 Read 99",
            "8:0 Re\0ad 99", "8:0 Read 9\0009", "8:0 Read\0 99",
        ] as $pattern) {
            $corrupt = sprintf($pattern, '8:0 Read 99');
            $this->pmssWriteFile($path, $corrupt."\n");
            $this->assertSame(null, \pmssResourceLogReadBlkioReadWrite($path));
            // Bad rows cannot add to valid totals, regardless of their position.
            foreach ([$corrupt."\n8:1 Read 2\n8:1 Write 3\n", "8:1 Read 2\n".$corrupt."\n8:1 Write 3\n"] as $raw) {
                $this->pmssWriteFile($path, $raw);
                $this->assertSame(['read' => 2, 'write' => 3], \pmssResourceLogReadBlkioReadWrite($path));
            }
        }
    }

    public function testKeyValueRejectsCorruptionWithoutClaimingField(): void
    {
        $path = $this->pmssMakeTempDir('pmss-v1-counter-', 0700).'/memory.stat';
        foreach (["\0rss 99", "rss 99\0", " \0rss 99\t", "rss 99\0 \t",
            "r\0ss 99", "rss 9\0009", "rss\0 99", "rss \00099"] as $corrupt) {
            $this->pmssWriteFile($path, $corrupt."\n");
            $this->assertSame([], \pmssResourceLogReadMemoryStatFields($path, ['rss']));
            $this->assertSame(null, \pmssResourceLogReadMemoryStatField($path, 'rss'));
            // A corrupt numeric row must not consume the first-valid-row slot.
            $this->pmssWriteFile($path, $corrupt."\nrss 12\nrss 34\nswap 0\n");
            $this->assertSame(['rss' => 12, 'swap' => 0], \pmssResourceLogReadMemoryStatFields($path, ['rss', 'swap']));
            $this->assertSame(12, \pmssResourceLogReadMemoryStatField($path, 'rss'));
        }
    }

    public function testBlkioRejectsMalformedDevicesWithoutDiscardingValidRows(): void
    {
        $path = $this->pmssMakeTempDir('pmss-v1-device-', 0700).'/blkio';
        foreach (['invalid', 'Total', '8', ':0', '8:', '8::0', '8:0:1', '-8:0',
            '8:-1', '+8:0', '8:+1', '8.0:1', '8:1.0', '8e0:1', '8:0junk', '/dev/fixture0'] as $device) {
            $bad = $device.' Read 99'."\n".$device.' Write 88'."\n";
            $this->pmssWriteFile($path, $bad);
            $this->assertSame(null, \pmssResourceLogReadBlkioReadWrite($path), $device);
            // Neither order lets malformed devices add to otherwise valid totals.
            foreach ([$bad."8:0 Read 2\n8:1 Write 3\n", "8:0 Read 2\n8:1 Write 3\n".$bad] as $raw) {
                $this->pmssWriteFile($path, $raw);
                $this->assertSame(['read' => 2, 'write' => 3], \pmssResourceLogReadBlkioReadWrite($path), $device);
            }
        }
        // Device fields stay textual: zeroes, multiple digits and row whitespace remain valid.
        $this->pmssWriteFile($path, " \t0:0 Read 0\r\n008:000 Read 2\n259:65535 Write 3\n");
        $this->assertSame(['read' => 2, 'write' => 3], \pmssResourceLogReadBlkioReadWrite($path));
    }

    public function testOrdinaryRowsKeepWhitespaceZeroAndOverflowPolicies(): void
    {
        $root = $this->pmssWriteFiles($this->pmssMakeTempDir('pmss-v1-counter-', 0700), [
            'blkio' => " \t8:0 Read 0002 \r\n8:1 Read 3\r\n8:0 Write 0\nTotal 5\n8:0 Read ".PHP_INT_MAX."\n",
            'memory.stat' => "\r\n \trss 00012 \r\nrss 34\ncache ".PHP_INT_MAX."\ncache 5\nswap 0\nhuge ".PHP_INT_MAX."0\nhuge 7\n",
        ]);
        $this->assertSame(['read' => 5, 'write' => 0], \pmssResourceLogReadBlkioReadWrite($root.'/blkio'));
        $this->assertSame(['rss' => 12, 'swap' => 0], \pmssResourceLogReadMemoryStatFields($root.'/memory.stat', ['rss', 'cache', 'swap', 'huge']));
        $this->assertSame([], \pmssResourceLogReadMemoryStatFields($root.'/memory.stat', []));
        $this->assertSame(null, \pmssResourceLogReadBlkioReadWrite($root.'/missing'));
        $this->assertSame([], \pmssResourceLogReadMemoryStatFields($root.'/missing', ['rss']));
    }

    public function testCorruptBfqBytesRetainThrottleFallbackForBothCollectors(): void
    {
        $slice = 'user.slice/user-1000.slice/';
        foreach (["\0008:0 Read 9000\n8:0 Write 8000\0\n",
            "invalid Read 9000\nTotal Write 8000\n", "8:-1 Read 9000\n8:0junk Write 8000\n"] as $bytes) {
            $root = $this->pmssWriteFiles($this->pmssMakeTempDir('pmss-v1-counter-', 0700), [
                'blkio/'.$slice.'blkio.bfq.io_service_bytes_recursive' => $bytes,
                'blkio/'.$slice.'blkio.bfq.io_serviced_recursive' => "8:0 Read 90\n8:0 Write 80\n",
                'blkio/'.$slice.'blkio.throttle.io_service_bytes_recursive' => "8:0 Read 100\n8:0 Write 200\n",
                'blkio/'.$slice.'blkio.throttle.io_serviced_recursive' => "8:0 Read 10\n8:0 Write 20\n",
            ]);
            $this->assertSame([
                'io_read' => 100, 'io_write' => 200, 'io_read_ops' => 10, 'io_write_ops' => 20,
                'io_source' => 'throttle', 'io_scope' => 'recursive',
            ], \pmssResourceLogReadCountersV1(1000, $root));
            $this->assertSame([
                'io_bytes_read' => 100, 'io_bytes_write' => 200, 'io_ops_read' => 10, 'io_ops_write' => 20,
            ], \pmssUserMetricsCollect(1000, $root));
        }
    }

    public function testCorruptOpsKeepBillingAtomicAndTelemetryPartial(): void
    {
        $slice = 'user.slice/user-1000.slice/';
        foreach (["\0008:0 Read 10\n8:0 Write 20\0\n",
            "invalid Read 10\nTotal Write 20\n", "8:-1 Read 10\n8:0junk Write 20\n"] as $ops) {
            $root = $this->pmssWriteFiles($this->pmssMakeTempDir('pmss-v1-counter-', 0700), [
                'blkio/'.$slice.'blkio.bfq.io_service_bytes_recursive' => "8:0 Read 100\n8:0 Write 200\n",
                'blkio/'.$slice.'blkio.bfq.io_serviced_recursive' => $ops,
                'memory/'.$slice.'memory.stat' => "\0total_rss 900\ntotal_cache 800\0\nrss 12\ncache 0\n",
                'cpuacct/'.$slice.'cpuacct.stat' => "\0user 99\nuser 2\nsystem 3\0\nsystem 4\n",
            ]);
            $this->assertSame(['memory_anon' => 12, 'memory_file' => 0], \pmssResourceLogReadCountersV1(1000, $root));
            $this->assertSame([
                'cpu_user_ticks' => 2, 'cpu_system_ticks' => 4, 'mem_rss' => 12, 'mem_cache' => 0,
                'io_bytes_read' => 100, 'io_bytes_write' => 200,
            ], \pmssUserMetricsCollect(1000, $root));
        }
    }
}
