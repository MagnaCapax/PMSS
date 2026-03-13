<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/dailyAccumulator.php';

class ResourceStatsDailyAccumulatorTest extends TestCase
{
    public function testEmptyAccumulatorReturnsEmptyResults(): void
    {
        $this->assertEquals([], (new \ResourceStatsDailyAccumulator())->results());
    }

    public function testSkipsFirstDaySamples(): void
    {
        $acc = new \ResourceStatsDailyAccumulator();
        $day1 = strtotime('2026-02-12 00:00:00');
        $day2 = strtotime('2026-02-13 00:05:00');

        $acc->addSample([
            'timestamp' => $day1,
            'io_read' => 100.0,
            'io_write' => 200.0,
            'cpu' => 300.0,
            'memory' => 1024 * 1024 * 1024,
            'tasks' => 2.0,
        ], 0.0833);
        $acc->addSample([
            'timestamp' => $day2,
            'io_read' => 50.0,
            'io_write' => 25.0,
            'cpu' => 100.0,
            'memory' => 1024 * 1024 * 1024,
            'tasks' => 4.0,
        ], 0.0833);

        $daily = $acc->results();
        $this->assertTrue(isset($daily['2026/02/13']));
        $this->assertTrue(!isset($daily['2026/02/12']));
        $this->assertEquals(50.0, $daily['2026/02/13']['io_read']);
    }

    public function testTracksLaterDayOpsCpuAndRamHours(): void
    {
        $acc = new \ResourceStatsDailyAccumulator();
        $day1 = strtotime('2026-02-12 00:00:00');
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
        ], 0.5);
        $acc->addSample([
            'timestamp' => $day2,
            'io_read' => 10.0,
            'io_write' => 20.0,
            'io_read_ops' => 30.0,
            'io_write_ops' => 40.0,
            'cpu' => 50.0,
            'memory' => 2 * 1024 * 1024 * 1024,
            'tasks' => 6.0,
        ], 0.25);

        $daily = $acc->results();

        $this->assertEquals(30.0, $daily['2026/02/13']['io_read_ops']);
        $this->assertEquals(40.0, $daily['2026/02/13']['io_write_ops']);
        $this->assertEquals(50.0, $daily['2026/02/13']['cpu']);
        $this->assertTrue(abs($daily['2026/02/13']['ram_hours'] - 0.5) < 0.0001);
        $this->assertEquals(2 * 1024 * 1024 * 1024, $daily['2026/02/13']['memory']);
        $this->assertEquals(6.0, $daily['2026/02/13']['tasks']);
    }
}
