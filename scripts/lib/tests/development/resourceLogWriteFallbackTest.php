<?php
/** Verify recursive throttle write fallback and safe billing source transitions. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/log.php';
require_once dirname(__DIR__, 2).'/resources/metrics.php';

class ResourceLogWriteFallbackTest extends TestCase
{
    /** Private fixtures exercise scripts/lib/resources/v1.php without host cgroups. */
    private function root(array $overrides = []): string
    {
        // Distinct devices and read totals expose accidental replacement or addition of BFQ reads.
        $files = array_replace([
            'blkio.bfq.io_service_bytes_recursive' => "8:0 Read 9000\n8:0 Write 0\n",
            'blkio.bfq.io_serviced_recursive' => "8:0 Read 90\n8:0 Write 0\n",
            'blkio.throttle.io_service_bytes_recursive' => "9:0 Read 123\n9:0 Write 8000\n",
            'blkio.throttle.io_serviced_recursive' => "9:0 Read 456\n9:0 Write 80\n",
        ], $overrides);
        $tree = [];
        foreach ($files as $file => $raw) {
            if ($raw !== null) $tree['blkio/user.slice/user-1000.slice/'.$file] = $raw;
        }
        return $this->pmssWriteFiles($this->pmssMakeTempDir('pmss-write-fallback-', 0700), $tree);
    }

    public function testBothCollectorsKeepBfqReadsAndUseThrottleWrites(): void
    {
        $root = $this->root();
        $this->assertSame([
            'io_read' => 9000, 'io_write' => 8000, 'io_read_ops' => 90, 'io_write_ops' => 80,
            'io_source' => 'bfq-read-throttle-write', 'io_scope' => 'recursive',
        ], \pmssResourceLogReadCountersV1(1000, $root));
        $this->assertSame([
            'io_bytes_read' => 9000, 'io_bytes_write' => 8000, 'io_ops_read' => 90, 'io_ops_write' => 80,
        ], \pmssUserMetricsCollect(1000, $root));
        $this->assertSame(null, \pmssResourceLogReadCountersV1(1001, $root));
    }

    public function testUnavailableThrottlePairLeavesBfqUnchanged(): void
    {
        // Missing, malformed, sentinel and overflowing totals cannot become a billing baseline.
        foreach (['io_service_bytes', 'io_serviced'] as $counter) {
            foreach ([null, '', "9:0 Write invalid\n", '9:0 Write '.PHP_INT_MAX."\n",
                "9:0 Write -1\n", '9:0 Write '.(PHP_INT_MAX - 1)."\n9:1 Write 2\n"] as $raw) {
                $root = $this->root(['blkio.throttle.'.$counter.'_recursive' => $raw]);
                $this->assertSame([
                    'io_read' => 9000, 'io_write' => 0, 'io_read_ops' => 90, 'io_write_ops' => 0,
                    'io_source' => 'bfq', 'io_scope' => 'recursive',
                ], \pmssResourceLogReadCountersV1(1000, $root));
            }
        }
    }

    public function testAnyPositiveBfqWriteRetainsBfqPrecedence(): void
    {
        // A bytes-only or ops-only BFQ write must not be replaced by another baseline.
        foreach ([[1, 0], [0, 1], [1, 1]] as [$bytes, $ops]) {
            $root = $this->root([
                'blkio.bfq.io_service_bytes_recursive' => "8:0 Read 9000\n8:0 Write {$bytes}\n",
                'blkio.bfq.io_serviced_recursive' => "8:0 Read 90\n8:0 Write {$ops}\n",
            ]);
            $sample = \pmssResourceLogReadCountersV1(1000, $root);
            $this->assertSame('bfq', $sample['io_source']);
            $this->assertSame($bytes, $sample['io_write']);
            $this->assertSame($ops, $sample['io_write_ops']);
        }
    }

    public function testMissingBfqOpsStillOmitsBillingIo(): void
    {
        // Throttle ops cannot complete the missing BFQ read-ops side of the atomic group.
        $root = $this->root(['blkio.bfq.io_serviced_recursive' => null]);
        $this->assertSame(null, \pmssResourceLogReadCountersV1(1000, $root));
        $this->assertSame(['io_bytes_read' => 9000, 'io_bytes_write' => 0], \pmssUserMetricsCollect(1000, $root));
    }

    public function testFallbackSumsRecursiveDevicesAndIgnoresPlainCounters(): void
    {
        // Large parent-only totals must neither replace subtree totals nor be added to them.
        $root = $this->root([
            'blkio.throttle.io_service_bytes_recursive' => "9:0 Write 8000\n9:1 Write 2000\nTotal 10000\n",
            'blkio.throttle.io_serviced_recursive' => "9:0 Write 80\n9:1 Write 20\nTotal 100\n",
            'blkio.throttle.io_service_bytes' => "9:0 Write 900000\n",
            'blkio.throttle.io_serviced' => "9:0 Write 9000\n",
        ]);
        $sample = \pmssResourceLogReadCountersV1(1000, $root);
        $this->assertSame(10000, $sample['io_write']);
        $this->assertSame(100, $sample['io_write_ops']);
        $this->assertSame(9000, $sample['io_read']);
    }

    public function testZeroThrottleBytesKeepBfqAndZeroThrottleOpsRemainValid(): void
    {
        $root = $this->root(['blkio.throttle.io_service_bytes_recursive' => "9:0 Read 123\n9:0 Write 0\n"]);
        $sample = \pmssResourceLogReadCountersV1(1000, $root);
        $this->assertSame('bfq', $sample['io_source']);
        $this->assertSame(0, $sample['io_write']);
        // A valid zero operation count is different from an unreadable operations file.
        $root = $this->root(['blkio.throttle.io_serviced_recursive' => "9:0 Read 456\n9:0 Write 0\n"]);
        $sample = \pmssResourceLogReadCountersV1(1000, $root);
        $this->assertSame('bfq-read-throttle-write', $sample['io_source']);
        $this->assertSame(8000, $sample['io_write']);
        $this->assertSame(0, $sample['io_write_ops']);
    }

    public function testSourceTransitionsReseedAndStableFallbackBooksOnlyGrowth(): void
    {
        $path = $this->pmssMakeTempDir('pmss-write-state-', 0700).'/state.json';
        $zero = ['io_read' => 0, 'io_write' => 0, 'io_read_ops' => 0, 'io_write_ops' => 0, 'cpu_nsec' => 0];
        // Exercise the actual acquisition and persistence boundary on both sides of the switch.
        // Smaller previous writes make a missing reseed charge historical usage.
        foreach ([
            ['blkio.throttle.io_service_bytes_recursive' => null, 'blkio.throttle.io_serviced_recursive' => null],
            ['blkio.bfq.io_service_bytes_recursive' => null, 'blkio.bfq.io_serviced_recursive' => null,
                'blkio.throttle.io_service_bytes_recursive' => "9:0 Read 100\n9:0 Write 200\n",
                'blkio.throttle.io_serviced_recursive' => "9:0 Read 1\n9:0 Write 2\n"],
        ] as $previousFiles) {
            $previous = \pmssResourceLogReadCountersV1(1000, $this->root($previousFiles));
            \pmssResourceLogUpdateState($path, $previous);
            $result = \pmssResourceLogUpdateState($path, \pmssResourceLogReadCountersV1(1000, $this->root()));
            $this->assertSame($zero, $result['delta']);
            $this->assertSame('bfq-read-throttle-write', $result['state']['io_source']);
        }
        // Once persisted, the mixed source must book growth using BFQ reads even as throttle reads jump.
        $root = $this->root([
            'blkio.bfq.io_service_bytes_recursive' => "8:0 Read 9500\n8:0 Write 0\n",
            'blkio.bfq.io_serviced_recursive' => "8:0 Read 95\n8:0 Write 0\n",
            'blkio.throttle.io_service_bytes_recursive' => "9:0 Read 99999\n9:0 Write 8500\n",
            'blkio.throttle.io_serviced_recursive' => "9:0 Read 999\n9:0 Write 85\n",
        ]);
        $result = \pmssResourceLogUpdateState($path, \pmssResourceLogReadCountersV1(1000, $root));
        $this->assertSame(['io_read' => 500, 'io_write' => 500, 'io_read_ops' => 5, 'io_write_ops' => 5, 'cpu_nsec' => 0], $result['delta']);
        // Recovery of BFQ writes must not book its entire cumulative value either.
        $root = $this->root([
            'blkio.bfq.io_service_bytes_recursive' => "8:0 Read 10000\n8:0 Write 900000\n",
            'blkio.bfq.io_serviced_recursive' => "8:0 Read 100\n8:0 Write 9000\n",
        ]);
        $result = \pmssResourceLogUpdateState($path, \pmssResourceLogReadCountersV1(1000, $root));
        $this->assertSame($zero, $result['delta']);
        $this->assertSame('bfq', $result['state']['io_source']);
    }
}
