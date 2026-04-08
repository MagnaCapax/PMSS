<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/show.php';

class ResourceReportTest extends TestCase
{
    /** @var string */
    private $statsDir;

    protected function setUp(): void
    {
        $this->statsDir = $this->pmssMakeTempDir('pmss-resource-report-').'/stats';
        @mkdir($this->statsDir, 0755, true);
    }

    private function writeUserStats(string $user, array $values): void
    {
        $this->writeUserData($user, $this->pmssBuildResourceStatsPayloadFromValues($values));
    }

    public function testBuildReportAggregatesRowsAndTotals(): void
    {
        $this->writeUserStats('alice', [
            'io_read' => $this->pmssBuildWindowValues(1000, 100, 10, 1), 'io_write' => $this->pmssBuildWindowValues(2000, 200, 20, 2), 'io_read_ops' => $this->pmssBuildWindowValues(3000, 300, 30, 3),
            'io_write_ops' => $this->pmssBuildWindowValues(4000, 400, 40, 4), 'cpu' => $this->pmssBuildWindowValues(5000, 500, 50, 5), 'ram_hours' => $this->pmssBuildWindowValues(6000, 600, 60, 6),
            'memory_current' => 700, 'memory_avg_month' => 70, 'tasks_current' => 7,
        ]);
        $this->writeUserStats('bob', [
            'io_read' => $this->pmssBuildWindowValues(11, 12, 13, 14), 'io_write' => $this->pmssBuildWindowValues(21, 22, 23, 24), 'io_read_ops' => $this->pmssBuildWindowValues(31, 32, 33, 34),
            'io_write_ops' => $this->pmssBuildWindowValues(41, 42, 43, 44), 'cpu' => $this->pmssBuildWindowValues(51, 52, 53, 54), 'ram_hours' => $this->pmssBuildWindowValues(61, 62, 63, 64),
            'memory_current' => 71, 'memory_avg_month' => 72, 'tasks_current' => 73,
        ]);

        $report = \pmssResourceBuildReport($this->statsDir, ['alice', 'bob']);

        $this->assertEquals([], $report['missing']);
        $this->assertEquals(1000.0, $report['rows']['alice']['io_read']['month']);
        $this->assertEquals(24.0, $report['rows']['bob']['io_write']['hour']);
        $this->assertEquals(1011.0, $report['totals']['io_read']['month']);
        $this->assertEquals(48.0, $report['totals']['io_write_ops']['hour']);
        $this->assertEquals(771.0, $report['totals']['memory_current']);
        $this->assertEquals(80.0, $report['totals']['tasks_current']);
    }

    public function testBuildReportMarksMissingWhenStatsFileMissing(): void
    {
        $report = \pmssResourceBuildReport($this->statsDir, ['ghost']);
        $this->assertEquals(['ghost'], $report['missing']);
        $this->assertEquals([], $report['rows']);
    }

    public function testBuildReportMarksMissingWhenRequiredMetricMissing(): void
    {
        $payload = $this->pmssBuildResourceStatsPayloadFromValues([
            'io_read' => $this->pmssBuildWindowValues(1), 'io_write' => $this->pmssBuildWindowValues(1), 'io_read_ops' => $this->pmssBuildWindowValues(1),
            'io_write_ops' => $this->pmssBuildWindowValues(1), 'cpu' => $this->pmssBuildWindowValues(1), 'ram_hours' => $this->pmssBuildWindowValues(1),
            'memory_current' => 1, 'memory_avg_month' => 1, 'tasks_current' => 1,
        ]);
        unset($payload['cpu']);
        $this->writeUserData('alice', $payload);

        $report = \pmssResourceBuildReport($this->statsDir, ['alice']);

        $this->assertEquals(['alice'], $report['missing']);
        $this->assertEquals([], $report['rows']);
    }

    public function testBuildReportDefaultsMissingOpsWindowsToZero(): void
    {
        $payload = $this->pmssBuildResourceStatsPayloadFromValues([
            'io_read' => $this->pmssBuildWindowValues(1), 'io_write' => $this->pmssBuildWindowValues(2), 'cpu' => $this->pmssBuildWindowValues(3), 'ram_hours' => $this->pmssBuildWindowValues(4),
            'memory_current' => 5, 'memory_avg_month' => 6, 'tasks_current' => 7,
        ]);
        unset($payload['io_read_ops']);
        unset($payload['io_write_ops']);
        $this->writeUserData('alice', $payload);

        $report = \pmssResourceBuildReport($this->statsDir, ['alice']);

        $this->assertEquals([], $report['missing']);
        $this->assertEquals(0.0, $report['rows']['alice']['io_read_ops']['month']);
        $this->assertEquals(0.0, $report['rows']['alice']['io_write_ops']['hour']);
    }

    private function writeUserData(string $user, array $data): void
    {
        $this->pmssWriteSerializedFixture($this->statsDir.'/'.$user, $data);
    }
}
