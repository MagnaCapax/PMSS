<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/log.php';

/**
 * The io_* deltas feed monthly IOPS accounting, so a sample must never book a whole
 * cumulative blkio counter as one interval: not on a missing baseline, and not when a
 * same-source counter drops below its stored baseline.
 */
class ResourceLogIoDecreaseGuardTest extends TestCase
{
    private function statePath(?array $previous): string
    {
        $path = $this->pmssMakeTempDir('pmss-resource-io-', 0700).'/state.json';
        if ($previous !== null) {
            $this->pmssWriteFile($path, (string) json_encode($previous));
        }
        return $path;
    }

    /** Counters as the cgroup v1 reader returns them: the io group always carries source and scope. */
    private function counters(int $read, int $write, int $readOps, int $writeOps, int $cpu = 500, string $source = 'bfq'): array
    {
        return ['memory' => 100, 'tasks' => 3, 'io_read' => $read, 'io_write' => $write,
            'io_read_ops' => $readOps, 'io_write_ops' => $writeOps, 'cpu_nsec' => $cpu,
            'io_source' => $source, 'io_scope' => 'recursive'];
    }

    private function previous(int $read, int $write, int $readOps, int $writeOps, int $cpu = 400, string $source = 'bfq'): array
    {
        return ['memory' => 90, 'tasks' => 2, 'ts' => 1, 'io_read' => $read, 'io_write' => $write,
            'io_read_ops' => $readOps, 'io_write_ops' => $writeOps, 'cpu_nsec' => $cpu,
            'io_source' => $source, 'io_scope' => 'recursive'];
    }

    public function testMissingBaselineBooksZeroIoAndKeepsCpuDelta(): void
    {
        // Characterization of the existing guard: no previous state means io_source differs (null vs bfq).
        $result = \pmssResourceLogUpdateState($this->statePath(null), $this->counters(5000000000000, 5, 4000000, 7));
        $this->assertSame(['io_read' => 0, 'io_write' => 0, 'io_read_ops' => 0, 'io_write_ops' => 0, 'cpu_nsec' => 500], $result['delta']);
        $this->assertSame(5000000000000, $result['state']['io_read']);
    }

    public function testNormalGrowthBooksDifferences(): void
    {
        // Negative control: increasing counters must still delta normally.
        $path = $this->statePath($this->previous(1000, 2000, 10, 20));
        $result = \pmssResourceLogUpdateState($path, $this->counters(1500, 2600, 14, 29, 450));
        $this->assertSame(['io_read' => 500, 'io_write' => 600, 'io_read_ops' => 4, 'io_write_ops' => 9, 'cpu_nsec' => 50], $result['delta']);
    }

    public function testEqualCountersBookZeroWithoutTriggeringTheGuard(): void
    {
        $path = $this->statePath($this->previous(1000, 2000, 10, 20));
        $result = \pmssResourceLogUpdateState($path, $this->counters(1000, 2000, 10, 20, 400));
        $this->assertSame(['io_read' => 0, 'io_write' => 0, 'io_read_ops' => 0, 'io_write_ops' => 0, 'cpu_nsec' => 0], $result['delta']);
    }

    public function testAnyDecreasedIoCounterZeroesAllFourIoDeltasAndKeepsCpu(): void
    {
        // A device leaving the summed set lowers one counter while the others still grow.
        foreach (['io_read', 'io_write', 'io_read_ops', 'io_write_ops'] as $dropped) {
            $current = ['io_read' => 1500, 'io_write' => 2600, 'io_read_ops' => 14, 'io_write_ops' => 29];
            $current[$dropped] = $dropped === 'io_read' || $dropped === 'io_write' ? 100 : 1;
            $path = $this->statePath($this->previous(1000, 2000, 10, 20));
            $result = \pmssResourceLogUpdateState($path, $this->counters($current['io_read'], $current['io_write'],
                $current['io_read_ops'], $current['io_write_ops'], 450));
            $this->assertSame(['io_read' => 0, 'io_write' => 0, 'io_read_ops' => 0, 'io_write_ops' => 0, 'cpu_nsec' => 50],
                $result['delta'], $dropped.' decrease must zero every io delta');
            // The new value is the baseline, so the next interval deltas normally.
            $next = \pmssResourceLogUpdateState($path, $this->counters($current['io_read'] + 7, $current['io_write'] + 8,
                $current['io_read_ops'] + 9, $current['io_write_ops'] + 10, 460));
            $this->assertSame(['io_read' => 7, 'io_write' => 8, 'io_read_ops' => 9, 'io_write_ops' => 10, 'cpu_nsec' => 10], $next['delta']);
        }
    }

    public function testSourceChangeStillReseedsToZero(): void
    {
        $path = $this->statePath($this->previous(1000, 2000, 10, 20, 400, 'throttle'));
        $result = \pmssResourceLogUpdateState($path, $this->counters(9000, 8000, 90, 80, 450, 'bfq'));
        $this->assertSame(['io_read' => 0, 'io_write' => 0, 'io_read_ops' => 0, 'io_write_ops' => 0, 'cpu_nsec' => 50], $result['delta']);
    }

    public function testScopeChangeStillReseedsToZero(): void
    {
        $previous = ['io_scope' => 'local'] + $this->previous(1000, 2000, 10, 20);
        $result = \pmssResourceLogUpdateState($this->statePath($previous), $this->counters(9000, 8000, 90, 80, 450));
        $this->assertSame(['io_read' => 0, 'io_write' => 0, 'io_read_ops' => 0, 'io_write_ops' => 0, 'cpu_nsec' => 50], $result['delta']);
    }
}
