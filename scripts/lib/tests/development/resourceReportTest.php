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
        $this->assertEquals(771.0, $report['totals']['memory']['current']);
        $this->assertEquals(80.0, $report['totals']['tasks']['current']);
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
        $this->assertEquals(5.0, $report['rows']['alice']['memory']['current']);
        $this->assertEquals(6.0, $report['rows']['alice']['memory']['avg_month']);
        $this->assertEquals(7.0, $report['rows']['alice']['tasks']['current']);
    }

    public function testStoredPayloadReportRowMatchesSnapshot(): void
    {
        $payload = $this->pmssBuildResourceStatsPayloadFromValues([
            'io_read' => $this->pmssBuildWindowValues(10, 9, 8, 7), 'io_write' => $this->pmssBuildWindowValues(20, 19, 18, 17), 'io_read_ops' => $this->pmssBuildWindowValues(30, 29, 28, 27),
            'io_write_ops' => $this->pmssBuildWindowValues(40, 39, 38, 37), 'cpu' => $this->pmssBuildWindowValues(50, 49, 48, 47), 'ram_hours' => $this->pmssBuildWindowValues(60, 59, 58, 57),
            'memory_current' => 70, 'memory_avg_month' => 69, 'tasks_current' => 11,
        ]);

        $this->assertEquals([
            'io_read' => ['month' => 10.0, 'week' => 9.0, 'day' => 8.0, 'hour' => 7.0],
            'io_write' => ['month' => 20.0, 'week' => 19.0, 'day' => 18.0, 'hour' => 17.0],
            'io_read_ops' => ['month' => 30.0, 'week' => 29.0, 'day' => 28.0, 'hour' => 27.0],
            'io_write_ops' => ['month' => 40.0, 'week' => 39.0, 'day' => 38.0, 'hour' => 37.0],
            'cpu' => ['month' => 50.0, 'week' => 49.0, 'day' => 48.0, 'hour' => 47.0],
            'ram_hours' => ['month' => 60.0, 'week' => 59.0, 'day' => 58.0, 'hour' => 57.0],
            'memory' => ['current' => 70.0, 'avg_month' => 69.0],
            'tasks' => ['current' => 11.0],
        ], \pmssResourceStoredPayloadReportRow($payload));
    }

    public function testBuildReportSingleUserMatchesSnapshot(): void
    {
        $this->writeUserStats('alice', [
            'io_read' => $this->pmssBuildWindowValues(10, 9, 8, 7), 'io_write' => $this->pmssBuildWindowValues(20, 19, 18, 17), 'io_read_ops' => $this->pmssBuildWindowValues(30, 29, 28, 27),
            'io_write_ops' => $this->pmssBuildWindowValues(40, 39, 38, 37), 'cpu' => $this->pmssBuildWindowValues(50, 49, 48, 47), 'ram_hours' => $this->pmssBuildWindowValues(60, 59, 58, 57),
            'memory_current' => 70, 'memory_avg_month' => 69, 'tasks_current' => 11,
        ]);

        $this->assertEquals([
            'rows' => ['alice' => [
                'io_read' => ['month' => 10.0, 'week' => 9.0, 'day' => 8.0, 'hour' => 7.0],
                'io_write' => ['month' => 20.0, 'week' => 19.0, 'day' => 18.0, 'hour' => 17.0],
                'io_read_ops' => ['month' => 30.0, 'week' => 29.0, 'day' => 28.0, 'hour' => 27.0],
                'io_write_ops' => ['month' => 40.0, 'week' => 39.0, 'day' => 38.0, 'hour' => 37.0],
                'cpu' => ['month' => 50.0, 'week' => 49.0, 'day' => 48.0, 'hour' => 47.0],
                'ram_hours' => ['month' => 60.0, 'week' => 59.0, 'day' => 58.0, 'hour' => 57.0],
                'memory' => ['current' => 70.0, 'avg_month' => 69.0],
                'tasks' => ['current' => 11.0],
            ]],
            'missing' => [],
            'totals' => [
                'io_read' => ['month' => 10.0, 'week' => 9.0, 'day' => 8.0, 'hour' => 7.0],
                'io_write' => ['month' => 20.0, 'week' => 19.0, 'day' => 18.0, 'hour' => 17.0],
                'io_read_ops' => ['month' => 30.0, 'week' => 29.0, 'day' => 28.0, 'hour' => 27.0],
                'io_write_ops' => ['month' => 40.0, 'week' => 39.0, 'day' => 38.0, 'hour' => 37.0],
                'cpu' => ['month' => 50.0, 'week' => 49.0, 'day' => 48.0, 'hour' => 47.0],
                'ram_hours' => ['month' => 60.0, 'week' => 59.0, 'day' => 58.0, 'hour' => 57.0],
                'memory' => ['current' => 70.0, 'avg_month' => 69.0],
                'tasks' => ['current' => 11.0],
            ],
        ], \pmssResourceBuildReport($this->statsDir, ['alice']));
    }

    private function writeUserData(string $user, array $data): void
    {
        $this->pmssWriteSerializedFixture($this->statsDir.'/'.$user, $data);
    }
}
