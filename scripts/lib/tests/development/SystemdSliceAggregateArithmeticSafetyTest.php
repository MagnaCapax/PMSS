<?php
/** Guard integer arithmetic in scripts/lib/update/systemPrep/systemdSliceAggregate.php. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/systemPrep/systemdSliceAggregate.php';

class SystemdSliceAggregateArithmeticSafetyTest extends TestCase
{
    public function testExtremeCountersKeepExactBoundsWithoutFloatPromotion(): void
    {
        $maximum = PHP_INT_MAX;
        $percentageReserve = intdiv($maximum, 10) + 1;
        foreach ([
            [$maximum, 1024, $maximum - 1792],
            [$maximum - 1, 1024, $maximum - 1793],
            [$maximum - 8, 1024, $maximum - 1800],
            [$maximum, 1025, $maximum - 1794],
            [$maximum, $maximum, $maximum - $percentageReserve],
            [$maximum, intdiv($maximum, 5), $maximum - $percentageReserve],
            [$maximum, 0, $maximum - 1536],
            [$maximum, PHP_INT_MIN, $maximum - 1536],
            [PHP_INT_MIN, $maximum, 0], [-1, $maximum, 0], [0, $maximum, 0],
            [2559, $maximum, 0], [2560, $maximum, 1024],
            [24576, $maximum, 22118],
        ] as [$full, $minFree, $expected]) {
            $this->assertSame($expected, \pmssSystemdUserSliceAggregateMemoryMiB($full, $minFree));
        }
    }

    public function testOrdinaryRoundingAndReserveTransitionsStayUnchanged(): void
    {
        foreach ([
            [15360, 1024, 13824], [15361, 1024, 13824], [15370, 1024, 13833],
            [15371, 1024, 13833], [17920, 1024, 16128], [17921, 1024, 16129],
            [24576, 1023, 22785], [24576, 1024, 22784], [24576, 1025, 22782],
            [24576, 1026, 22781], [24576, 1027, 22780], [24576, 1028, 22779],
            [24576, -1, 23040], [24576, 0, 23040], [8192, 4096, 6656],
        ] as [$full, $minFree, $expected]) {
            // Include all four watermark remainders and either side of percentage ceilings.
            $this->assertSame($expected, \pmssSystemdUserSliceAggregateMemoryMiB($full, $minFree));
        }
    }

    public function testInstallerRefusesOverflowBeforeSizingOrPublication(): void
    {
        $load = $this->pmssInlinePhpLibraryInNamespace(
            'scripts/lib/update/systemPrep/systemdSliceAggregate.php', 'AggregateArithmeticFixture'
        );
        $rows = $this->pmssRunInlinePhpJson(<<<'PHP'
namespace AggregateArithmeticFixture;
// Every observation and write stays in memory; no host paths or systemd are used.
function is_file($path) { return true; }
function file_get_contents($path) { return "[Slice]\nMemoryMax=%%USER_SLICE_AGGREGATE_MEMORY_MAX%%M\n"; }
function pmssBalloonHeldMiB() { return $GLOBALS['balloon']; }
function pmssMinFreeMiB() { $GLOBALS['events'][] = 'minFree'; return 1024; }
function pmssRefreshManagedPathFile($path, $body, $label, $log, $options, &$ready) {
    $GLOBALS['events'][] = ['write', $path, $body];
    $ready = true;
}
PHP
            .$load.<<<'PHP'
$rows = [];
foreach ([[PHP_INT_MAX, 1], [PHP_INT_MAX - 1, 2], [1, PHP_INT_MAX],
    [PHP_INT_MAX, PHP_INT_MAX], [PHP_INT_MAX - 1024, 1025],
    [PHP_INT_MAX, 0], [PHP_INT_MAX - 1024, 1024], [0, PHP_INT_MAX],
    [14288, 10288], [24576, 0], [2048, 0]] as [$total, $balloon]) {
    $GLOBALS['balloon'] = $balloon;
    $GLOBALS['events'] = [];
    $logs = [];
    $ok = pmssSystemdUserSliceAggregateInstall('/systemd/user-.slice.d', '/config', $total, 'v1',
        static function ($line) use (&$logs) { $logs[] = $line; });
    $rows[] = [$ok, $GLOBALS['events'], $logs];
}
echo json_encode($rows);
PHP
        );
        $this->assertSame(11, count($rows));
        foreach (array_slice($rows, 0, 5) as [$ok, $events, $logs]) {
            $this->assertSame(false, $ok);
            $this->assertSame([], $events, 'overflow must not read min_free or replace the drop-in');
            $this->assertSame([
                '[WARN] Skipping user.slice aggregate memory bound: MemTotal plus balloon exceeds integer range',
            ], $logs);
        }
        foreach (array_slice($rows, 5, 5) as $index => [$ok, $events, $logs]) {
            $expected = $index < 3 ? PHP_INT_MAX - 1792 : 22784;
            $this->assertSame(true, $ok);
            $this->assertSame(['minFree', ['write', '/systemd/user.slice.d/15-pmss-aggregate.conf',
                "[Slice]\nMemoryMax=".$expected."M\n"]], $events);
            $this->assertStringContainsString('mode=v1, ready=yes', $logs[0]);
        }
        $this->assertSame(false, $rows[10][0], 'the existing small-host refusal remains');
        $this->assertSame(['minFree'], $rows[10][1]);
        $this->assertSame([
            '[WARN] Skipping user.slice aggregate memory bound: MemTotal 2048M leaves no useful tenant bound',
        ], $rows[10][2]);
    }
}
