<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/accumulator.php';

class ResourceStatsAccumulatorTest extends TestCase
{
    public function testAccumulatesTotalsAndAverages(): void
    {
        $now = time();
        $compare = ['day' => $now - 3600];
        $acc = new \ResourceStatsAccumulator($compare);

        $acc->addSample([
            'timestamp' => $now - 300,
            'io_read' => 100.0,
            'io_write' => 200.0,
            'cpu' => 300.0,
            'memory' => 1024 * 1024 * 1024,
            'tasks' => 2.0,
        ]);
        $acc->addSample([
            'timestamp' => $now,
            'io_read' => 50.0,
            'io_write' => 25.0,
            'cpu' => 100.0,
            'memory' => 1024 * 1024 * 1024,
            'tasks' => 4.0,
        ]);

        $results = $acc->results();
        $this->assertTrue($acc->hasSamples());
        $this->assertEquals(150.0, $results['raw']['io_read']['day']);
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
}
