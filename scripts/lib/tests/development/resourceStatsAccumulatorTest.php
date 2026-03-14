<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/accumulator.php';

class ResourceStatsAccumulatorTest extends TestCase
{
    public function testHasSamplesIsFalseBeforeAnySamples(): void
    {
        $acc = new \ResourceStatsAccumulator(['day' => time() - 3600]);

        $this->assertTrue(!$acc->hasSamples());
    }

    public function testDailyResultsAreEmptyBeforeAnySamples(): void
    {
        $this->assertEquals([], (new \ResourceStatsAccumulator([]))->results()['daily']);
    }

    public function testAccumulatesTotalsAndAverages(): void
    {
        $now = time();
        $compare = ['day' => $now - 3600];
        $acc = new \ResourceStatsAccumulator($compare);

        $acc->addSample([
            'timestamp' => $now - 300,
            'io_read' => 100.0,
            'io_write' => 200.0,
            'io_read_ops' => 10.0,
            'io_write_ops' => 20.0,
            'cpu' => 300.0,
            'memory' => 1024 * 1024 * 1024,
            'tasks' => 2.0,
        ]);
        $acc->addSample([
            'timestamp' => $now,
            'io_read' => 50.0,
            'io_write' => 25.0,
            'io_read_ops' => 5.0,
            'io_write_ops' => 7.0,
            'cpu' => 100.0,
            'memory' => 1024 * 1024 * 1024,
            'tasks' => 4.0,
        ]);

        $results = $acc->results();
        $this->assertTrue($acc->hasSamples());
        $this->assertEquals(150.0, $results['raw']['io_read']['day']);
        $this->assertEquals(15.0, $results['raw']['io_read_ops']['day']);
        $this->assertEquals(27.0, $results['raw']['io_write_ops']['day']);
        $this->assertEquals(3.0, $results['tasks']['day']);
        $this->assertEquals(1024 * 1024 * 1024, $results['memory']['day']);
    }

    public function testRamHoursUsesSampleIntervals(): void
    {
        $now = time();
        $compare = ['day' => $now - 3600];
        $acc = new \ResourceStatsAccumulator($compare);

        $acc->addSample([
            'timestamp' => $now - 600,
            'io_read' => 0.0,
            'io_write' => 0.0,
            'cpu' => 0.0,
            'memory' => 1024 * 1024 * 1024,
            'tasks' => 1.0,
        ]);
        $acc->addSample([
            'timestamp' => $now,
            'io_read' => 0.0,
            'io_write' => 0.0,
            'cpu' => 0.0,
            'memory' => 1024 * 1024 * 1024,
            'tasks' => 1.0,
        ]);

        $results = $acc->results();
        $ramHours = $results['raw']['ram_hours']['day'];
        $this->assertTrue(abs($ramHours - 0.25) < 0.01);
    }

    public function testRamHoursFallsBackToFiveMinuteWindowsForLongGaps(): void
    {
        $now = time();
        $compare = ['day' => $now - (3 * 3600)];
        $acc = new \ResourceStatsAccumulator($compare);

        $acc->addSample([
            'timestamp' => $now - (2 * 3600),
            'io_read' => 0.0,
            'io_write' => 0.0,
            'cpu' => 0.0,
            'memory' => 1024 * 1024 * 1024,
            'tasks' => 1.0,
        ]);
        $acc->addSample([
            'timestamp' => $now,
            'io_read' => 0.0,
            'io_write' => 0.0,
            'cpu' => 0.0,
            'memory' => 1024 * 1024 * 1024,
            'tasks' => 1.0,
        ]);

        $ramHours = $acc->results()['raw']['ram_hours']['day'];
        $this->assertTrue(abs($ramHours - (10 / 60)) < 0.01);
    }

    public function testDailyResultsSkipFirstDaySamples(): void
    {
        $acc = new \ResourceStatsAccumulator([]);
        $day1 = strtotime('2026-02-12 00:00:00');
        $day2 = strtotime('2026-02-13 00:05:00');

        $acc->addSample([
            'timestamp' => $day1,
            'io_read' => 100.0,
            'io_write' => 200.0,
            'cpu' => 300.0,
            'memory' => 1024 * 1024 * 1024,
            'tasks' => 2.0,
        ]);
        $acc->addSample([
            'timestamp' => $day2,
            'io_read' => 50.0,
            'io_write' => 25.0,
            'cpu' => 100.0,
            'memory' => 1024 * 1024 * 1024,
            'tasks' => 4.0,
        ]);

        $daily = $acc->results()['daily'];
        $this->assertTrue(isset($daily['2026/02/13']));
        $this->assertTrue(!isset($daily['2026/02/12']));
        $this->assertEquals(50.0, $daily['2026/02/13']['io_read']);
    }

    public function testDailyResultsTrackLaterDayOpsCpuAndRamHours(): void
    {
        $acc = new \ResourceStatsAccumulator([]);
        $day1 = strtotime('2026-02-12 23:50:00');
        $day2 = strtotime('2026-02-13 00:05:00');

        $acc->addSample([
            'timestamp' => $day1,
            'io_read' => 1.0,
            'io_write' => 2.0,
            'io_read_ops' => 3.0,
            'io_write_ops' => 4.0,
            'cpu' => 5.0,
            'memory' => 1024 * 1024 * 1024,
            'tasks' => 1.0,
        ]);
        $acc->addSample([
            'timestamp' => $day2,
            'io_read' => 10.0,
            'io_write' => 20.0,
            'io_read_ops' => 30.0,
            'io_write_ops' => 40.0,
            'cpu' => 50.0,
            'memory' => 2 * 1024 * 1024 * 1024,
            'tasks' => 6.0,
        ]);

        $daily = $acc->results()['daily'];
        $this->assertEquals(30.0, $daily['2026/02/13']['io_read_ops']);
        $this->assertEquals(40.0, $daily['2026/02/13']['io_write_ops']);
        $this->assertEquals(50.0, $daily['2026/02/13']['cpu']);
        $this->assertTrue(abs($daily['2026/02/13']['ram_hours'] - 0.5) < 0.0001);
        $this->assertEquals(2 * 1024 * 1024 * 1024, $daily['2026/02/13']['memory']);
        $this->assertEquals(6.0, $daily['2026/02/13']['tasks']);
    }
}
