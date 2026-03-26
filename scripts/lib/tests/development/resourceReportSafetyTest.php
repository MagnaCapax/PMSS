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
}
