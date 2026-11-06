<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../cgroup/dynamicIopsShadow.php';

/** Contention excludes the stall of accounts held at their own read cap; temp files only. */
class DynamicIopsContentionTest extends TestCase
{
    private function psi(float $full): string
    {
        return "some avg10=1.00 avg60=1.00 avg300=99.00 total=1\nfull avg10=0.00 avg60=0.00 avg300=".sprintf('%.2f', $full)." total=1\n";
    }

    public function testParsersReadOnlyTheirOwnField(): void
    {
        $root = $this->pmssMakeTempDir('pmss-contention-', 0700);
        $this->pmssWriteFiles($root, ['psi' => $this->psi(42.5), 'bad' => "some avg300=5\n", 'high' => $this->psi(150),
            'served' => "8:0 Read 1200\n8:0 Write 7\n8:16 Read 99\nTotal 1306\n"]);
        $this->assertSame(42.5, \pmssPsiFullAvg300($root.'/psi'));
        foreach (['bad', 'high', 'missing'] as $file) $this->assertSame(null, \pmssPsiFullAvg300($root.'/'.$file));
        $this->assertSame(1200, \pmssBlkioReadCount($root.'/served', '8:0'));
        $this->assertSame(99, \pmssBlkioReadCount($root.'/served', '8:16'));
        $this->assertSame(null, \pmssBlkioReadCount($root.'/served', '9:0'));
    }

    public function testHeldMeansReadingAtTheRateTheCapDelivers(): void
    {
        // Cap 25 delivers 20 per second: 18 per second over 300 s is held, 17 is not.
        $this->assertTrue(\pmssDynamicIopsSliceHeld(25, 0, 5400, 300));
        $this->assertFalse(\pmssDynamicIopsSliceHeld(25, 0, 5099, 300));
        foreach ([[null, 0, 9000, 300], [0, 0, 9000, 300], [25, null, 9000, 300], [25, 9000, 0, 300], [25, 0, 9000, 59]] as $args) {
            $this->assertFalse(\pmssDynamicIopsSliceHeld(...$args));
        }
    }

    public function testNoHeldAccountKeepsTheHostValueAndHeldStallDropsOut(): void
    {
        $slices = [['full' => 80.0, 'held' => false], ['full' => 5.0, 'held' => false]];
        $this->assertSame(30.0, \pmssDynamicIopsContention(30.0, 1.0, $slices));
        $this->assertSame(70.0, \pmssDynamicIopsContention(70.0, null, [['full' => 2.0, 'held' => false]]));
        // Only a held account stalls: the disk is not contended.
        $this->assertSame(3.0, \pmssDynamicIopsContention(70.0, 3.0, [['full' => 95.0, 'held' => true], ['full' => 1.0, 'held' => false]]));
        // An unheld account stalls as well: contention stays, bounded by the host value.
        $this->assertSame(60.0, \pmssDynamicIopsContention(60.0, 3.0, [['full' => 95.0, 'held' => true], ['full' => 88.0, 'held' => false]]));
        $this->assertSame(0.0, \pmssDynamicIopsContention(70.0, null, [['full' => null, 'held' => true]]));
    }

    public function testScaleUsesTheNearestRowWithinHalfABucket(): void
    {
        $rows = [['time' => 1000, 'host' => 80.0, 'contention' => 20.0], ['time' => 1300, 'host' => 0.0, 'contention' => 0.0]];
        $samples = [['time' => 1100, 'psiFullAvg300' => 80, 'cpuIowaitPct' => 40, 'diskUtil' => 9],
            ['time' => 1200, 'psiFullAvg300' => 50], ['time' => 2000, 'psiFullAvg300' => 70], ['psiFullAvg300' => 70]];
        $scaled = iterator_to_array(\pmssDynamicIopsContentionScale($samples, $rows), false);
        $this->assertSame(['time' => 1100, 'psiFullAvg300' => 20.0, 'cpuIowaitPct' => 10.0, 'diskUtil' => 9], $scaled[0]);
        $this->assertSame($samples[1], $scaled[1]);
        $this->assertSame($samples[2], $scaled[2]);
        $this->assertSame($samples[3], $scaled[3]);
    }

    public function testObserveRecordsAnHourOfRowsAndNeedsTheUnifiedHierarchy(): void
    {
        $root = $this->pmssMakeTempDir('pmss-contention-', 0700);
        $this->pmssWriteFiles($root, ['host' => $this->psi(40.0), 'unified/user.slice/.keep' => '',
            'unified/system.slice/io.pressure' => $this->psi(2.0)]);
        $state = $root.'/state/contention.json';
        $old = ['time' => 1000, 'counts' => [], 'rows' => [['time' => 1000, 'host' => 1.0, 'contention' => 1.0],
            ['time' => 4700, 'host' => 50.0, 'contention' => 5.0]]];
        $this->pmssWriteFiles($root, ['state/contention.json' => json_encode($old)]);
        chmod($state, 0600);
        $first = \pmssDynamicIopsContentionObserve([], '8:0', 5000, false, $state, $root.'/unified', $root.'/host');
        $this->assertSame(['time' => 5000, 'host' => 40.0, 'held' => 0, 'contention' => 40.0], $first['current']);
        $this->assertSame([4700, 5000], array_column($first['rows'], 'time'));
        $saved = json_decode((string) file_get_contents($state), true);
        $this->assertSame(5000, $saved['time']);
        $this->assertSame(0600, fileperms($state) & 0777);
        $preview = \pmssDynamicIopsContentionObserve([], '8:0', 5300, true, $state, $root.'/unified', $root.'/host');
        $this->assertSame(5000, json_decode((string) file_get_contents($state), true)['time']);
        $this->assertSame(5300, $preview['current']['time']);
        $absent = \pmssDynamicIopsContentionObserve([], '8:0', 5300, false, $state, $root.'/none', $root.'/host');
        $this->assertSame(null, $absent['current']);
    }
}
