<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/show.php';

class ResourceReportSafetyWakeupProbe
{
    /** @var string */
    public $markerPath;

    public function __construct(string $markerPath)
    {
        $this->markerPath = $markerPath;
    }

    public function __wakeup(): void
    {
        @file_put_contents($this->markerPath, 'triggered');
    }
}

class resourceReportSafetyTest extends TestCase
{
    /** @var string */
    private $runtimeDir;

    /** @var string */
    private $statsDir;

    /** @var string */
    private $markerPath;

    public function setUp(): void
    {
        $this->runtimeDir = $this->pmssMakeTempDir('pmss-resource-safety-');
        $this->statsDir = $this->runtimeDir.'/resourceStats';
        $this->markerPath = $this->runtimeDir.'/wakeup-marker';
        @mkdir($this->statsDir, 0755, true);
    }

    public function testBuildReportRejectsSerializedObjectsWithoutWakeup(): void
    {
        @file_put_contents(
            $this->statsDir.'/alice',
            serialize(new ResourceReportSafetyWakeupProbe($this->markerPath))
        );

        $report = \pmssResourceBuildReport($this->statsDir, ['alice']);

        $this->assertEquals(['alice'], $report['missing']);
        $this->assertEquals([], $report['rows']);
        $this->assertTrue(!file_exists($this->markerPath));
    }

    public function testBuildReportSkipsInvalidUserTraversalKeys(): void
    {
        @file_put_contents(
            $this->runtimeDir.'/outside-stats',
            serialize($this->pmssBuildResourceStatsPayloadFromValues([
                'io_read' => $this->pmssBuildWindowValues(1),
                'io_write' => $this->pmssBuildWindowValues(2),
                'io_read_ops' => $this->pmssBuildWindowValues(3),
                'io_write_ops' => $this->pmssBuildWindowValues(4),
                'cpu' => $this->pmssBuildWindowValues(5),
                'ram_hours' => $this->pmssBuildWindowValues(6),
                'memory_current' => 7,
                'memory_avg_month' => 8,
                'tasks_current' => 9,
            ]))
        );

        $report = \pmssResourceBuildReport($this->statsDir, ['../outside-stats']);

        $this->assertEquals([], $report['missing']);
        $this->assertEquals([], $report['rows']);
    }
}
