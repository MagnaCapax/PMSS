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

    public function testObservationPathsFailSoftBeforeReadingAndPreserveValidBytes(): void
    {
        // Record reads without touching procfs or sysfs; malformed paths must never reach I/O.
        $script = <<<'PHP'
namespace ContentionPathFixture;
function file_get_contents($path) {
    $GLOBALS['reads'][] = $path;
    return $GLOBALS['raw'];
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/cgroup/dynamicIopsContention.php', 'ContentionPathFixture');
        $script .= <<<'PHP'
[$path, $GLOBALS['raw']] = json_decode(getenv('PMSS_TEST_CONTENTION_READ'), true);
$GLOBALS['reads'] = [];
echo json_encode([pmssPsiFullAvg300($path), pmssBlkioReadCount($path, '8:0'), $GLOBALS['reads']], JSON_PRESERVE_ZERO_FRACTION);
PHP;
        $raw = "full avg10=0.00 avg60=0.00 avg300=42.50 total=1\n8:0 Read 1200\n";
        foreach (['', "\0", "\0/fixture/pressure", "/fixture/pressure\0", "/fixture/pressure\0suffix",
            "/fixture/dir\0/pressure", " \0 "] as $path) {
            $this->assertSame([null, null, []], $this->pmssRunInlinePhpJson($script, [
                'PMSS_TEST_CONTENTION_READ' => json_encode([$path, $raw]),
            ]));
        }
        // Accepted filenames are passed verbatim, including relative paths and whitespace.
        foreach (['/fixture/pressure', 'relative-pressure', ' /fixture/pressure ', '/fixture/path with spaces', ' '] as $path) {
            foreach ([
                [$raw, 42.5, 1200],
                ["full avg300=0.00\n8:0 Read 0\n", 0.0, 0],
                ["full avg300=100.00\n8:0 Read ".PHP_INT_MAX."\n", 100.0, PHP_INT_MAX],
                [false, null, null],
                ['', null, null],
                ["some avg300=5.00\n8:0 Write 9\n", null, null],
                ["full avg300=150.00\n8:0 Read ".PHP_INT_MAX."0\n", null, null],
            ] as [$contents, $psi, $count]) {
                $this->assertSame([$psi, $count, [$path, $path]], $this->pmssRunInlinePhpJson($script, [
                    'PMSS_TEST_CONTENTION_READ' => json_encode([$path, $contents]),
                ]));
            }
        }
    }

    public function testHeldMeansReadingAtTheRateTheCapDelivers(): void
    {
        // Cap 25 delivers 20 per second: 15 per second over 300 s is held, just under it is not.
        $this->assertTrue(\pmssDynamicIopsSliceHeld(25, 0, 4500, 300));
        $this->assertFalse(\pmssDynamicIopsSliceHeld(25, 0, 4499, 300));
        foreach ([[null, 0, 9000, 300], [0, 0, 9000, 300], [25, null, 9000, 300], [25, 9000, 0, 300], [25, 0, 9000, 59]] as $args) {
            $this->assertFalse(\pmssDynamicIopsSliceHeld(...$args));
        }
    }

    public function testReadersTheThrottleKeepsWaitingCountAsHeld(): void
    {
        // A reader the throttle keeps waiting gets 76-90% of its cap per cycle (#1249): 80% and 76% are held.
        $this->assertTrue(\pmssDynamicIopsSliceHeld(100, 1000, 1000 + 17280, 216));
        $this->assertTrue(\pmssDynamicIopsSliceHeld(300, 0, 68400, 300));
        // A light reader at 8% of its cap is not held.
        $this->assertFalse(\pmssDynamicIopsSliceHeld(20, 0, 480, 300));
    }

    /** scripts/lib/cgroup/dynamicIopsContention.php accepts only unsigned counter baselines. */
    public function testNegativeCountersCannotEstablishHeldReads(): void
    {
        foreach ([[-1, 4500], [PHP_INT_MIN, PHP_INT_MAX], [-100, -1], [-1, -1], [0, -1]] as [$before, $after]) {
            $this->assertFalse(\pmssDynamicIopsSliceHeld(25, $before, $after, 300));
        }
        $this->assertFalse(\pmssDynamicIopsSliceHeld(25, PHP_INT_MAX - 1, PHP_INT_MAX, 300));
        $this->assertTrue(\pmssDynamicIopsSliceHeld(25, 0, PHP_INT_MAX, 300));
        foreach ([-1, -900, PHP_INT_MIN] as $then) {
            $this->assertSame([], \pmssDynamicIopsHeldLastCycle(['time' => $then, 'held' => [1001]], 100));
        }
    }

    public function testObserverDiscardsNegativeEpochsBeforeComputingElapsedTime(): void
    {
        $script = <<<'PHP'
namespace ContentionEpochFixture;
function pmssJsonFileReadAssoc($path, $safe) { return ['time' => $GLOBALS['then'], 'counts' => [1001 => 0]]; }
function file_get_contents($path) { return "full avg300=1.00\n8:0 Read 6000\n"; }
function is_dir($path) { return true; }
function pmssCgroupDirectUserUidOrError($user, &$errors) { return 1001; }
function pmssCgroupDirectUserSliceDir($uid) { return '/fixture/slice'; }
function pmssCgroupDirectDeviceValueParse($raw, $device) { return 25; }
function pmssUnsignedDecimalIntParse($raw) { return (int) $raw; }
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/cgroup/dynamicIopsContention.php', 'ContentionEpochFixture');
        // Only acquisition helpers are replaced; the observer and held predicate run unchanged.
        $script .= <<<'PHP'
$rows = [];
foreach ([-1, -900, PHP_INT_MIN, 0, 100] as $then) {
    $GLOBALS['then'] = $then;
    $row = pmssDynamicIopsContentionObserve(['fixtur01' => []], '8:0', 400, true);
    $rows[] = $row['held']['fixtur01'];
}
echo json_encode($rows);
PHP;
        $this->assertSame([false, false, false, true, true], $this->pmssRunInlinePhpJson($script));
    }

    public function testContentionLeavesOutReadersAtHalfTheirCapButThePanelDoesNot(): void
    {
        // Readers at 60-76% of their cap stalled 35-75% on three hosts; readers at 36% or less stalled 23% or less (#1249).
        $this->assertTrue(\pmssDynamicIopsSliceHeld(100, 0, 18000, 300, \PMSS_DYNAMIC_IOPS_EXCLUDE_RATIO));
        $this->assertFalse(\pmssDynamicIopsSliceHeld(100, 0, 18000, 300));
        // Cap 25 delivers 20 per second: half of it over 300 s is left out, just under it is not.
        $this->assertTrue(\pmssDynamicIopsSliceHeld(25, 0, 3000, 300, \PMSS_DYNAMIC_IOPS_EXCLUDE_RATIO));
        $this->assertFalse(\pmssDynamicIopsSliceHeld(25, 0, 2999, 300, \PMSS_DYNAMIC_IOPS_EXCLUDE_RATIO));
        $this->assertFalse(\pmssDynamicIopsSliceHeld(100, 0, 10800, 300, \PMSS_DYNAMIC_IOPS_EXCLUDE_RATIO));
    }

    public function testHeldLastCycleIsASetOfRecentPositiveUids(): void
    {
        $this->assertSame([1001 => true, 1004 => true], \pmssDynamicIopsHeldLastCycle(['time' => 700, 'held' => [1001, 1004]], 1000));
        $this->assertSame([1001 => true], \pmssDynamicIopsHeldLastCycle(['time' => 100, 'held' => [1001, 0, -3, '1002', null]], 1000));
        foreach ([['time' => 99, 'held' => [1001]], ['time' => 1000, 'held' => [1001]], ['time' => 1300, 'held' => [1001]],
            ['time' => 700], ['time' => 700, 'held' => 'x'], ['held' => [1001]], []] as $state) {
            $this->assertSame([], \pmssDynamicIopsHeldLastCycle($state, 1000));
        }
    }

    public function testCapRiseDoesNotSnapContentionBackToTheHostValue(): void
    {
        // Cycle 1: the reader is held; the others are quiet. Cycle 2: its cap rose, it reads under the new cap,
        // and its avg300 still carries the held cycle. Held last cycle keeps it out, so contention stays with the quiet accounts.
        $c1 = \pmssDynamicIopsContention(85.0, 3.0, [['full' => 90.0, 'held' => true], ['full' => 4.0, 'held' => false]]);
        $held = \pmssDynamicIopsHeldLastCycle(['time' => 1000, 'held' => [1001]], 1300);
        $c2 = \pmssDynamicIopsContention(80.0, 3.0, [['full' => 33.0, 'held' => false || isset($held[1001])], ['full' => 4.0, 'held' => false]]);
        $this->assertSame(4.0, $c1);
        $this->assertSame(4.0, $c2);
        // Twelve five-minute host samples at 85% scaled by those rows give a blend at the idle pressure (5), so the idle multiplier applies.
        $rows = []; $samples = [];
        for ($i = 0; $i < 12; $i++) { $t = 4000 - 300 * $i; $rows[] = ['time' => $t, 'host' => 85.0, 'contention' => 4.0]; $samples[] = ['time' => $t, 'psiFullAvg300' => 85.0]; }
        $pressure = \pmssDynamicIopsPressure(\pmssDynamicIopsContentionScale($samples, $rows), 4000);
        $this->assertTrue($pressure['blend'] !== null && $pressure['blend'] <= 5.0);
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
        $this->assertSame(['time' => 5000, 'host' => 40.0, 'held' => 0, 'excluded' => 0, 'contention' => 40.0], $first['current']);
        $this->assertSame([4700, 5000], array_column($first['rows'], 'time'));
        $saved = $this->pmssReadJsonArrayFile($state);
        $this->assertSame(5000, $saved['time']);
        $this->assertSame([], $saved['held']);
        $this->assertSame(0600, fileperms($state) & 0777);
        $preview = \pmssDynamicIopsContentionObserve([], '8:0', 5300, true, $state, $root.'/unified', $root.'/host');
        $this->assertSame(5000, $this->pmssReadJsonArrayFile($state)['time']);
        $this->assertSame(5300, $preview['current']['time']);
        $absent = \pmssDynamicIopsContentionObserve([], '8:0', 5300, false, $state, $root.'/none', $root.'/host');
        $this->assertSame(null, $absent['current']);
    }
}
