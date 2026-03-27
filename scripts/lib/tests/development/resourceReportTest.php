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
            'io_read' => ['month' => 1000, 'week' => 100, 'day' => 10, 'hour' => 1],
            'io_write' => ['month' => 2000, 'week' => 200, 'day' => 20, 'hour' => 2],
            'io_read_ops' => ['month' => 3000, 'week' => 300, 'day' => 30, 'hour' => 3],
            'io_write_ops' => ['month' => 4000, 'week' => 400, 'day' => 40, 'hour' => 4],
            'cpu' => ['month' => 5000, 'week' => 500, 'day' => 50, 'hour' => 5],
            'ram_hours' => ['month' => 6000, 'week' => 600, 'day' => 60, 'hour' => 6],
            'memory_current' => 700,
            'memory_avg_month' => 70,
            'tasks_current' => 7,
        ]);
        $this->writeUserStats('bob', [
            'io_read' => ['month' => 11, 'week' => 12, 'day' => 13, 'hour' => 14],
            'io_write' => ['month' => 21, 'week' => 22, 'day' => 23, 'hour' => 24],
            'io_read_ops' => ['month' => 31, 'week' => 32, 'day' => 33, 'hour' => 34],
            'io_write_ops' => ['month' => 41, 'week' => 42, 'day' => 43, 'hour' => 44],
            'cpu' => ['month' => 51, 'week' => 52, 'day' => 53, 'hour' => 54],
            'ram_hours' => ['month' => 61, 'week' => 62, 'day' => 63, 'hour' => 64],
            'memory_current' => 71,
            'memory_avg_month' => 72,
            'tasks_current' => 73,
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
            'io_read' => ['month' => 1, 'week' => 1, 'day' => 1, 'hour' => 1],
            'io_write' => ['month' => 1, 'week' => 1, 'day' => 1, 'hour' => 1],
            'io_read_ops' => ['month' => 1, 'week' => 1, 'day' => 1, 'hour' => 1],
            'io_write_ops' => ['month' => 1, 'week' => 1, 'day' => 1, 'hour' => 1],
            'cpu' => ['month' => 1, 'week' => 1, 'day' => 1, 'hour' => 1],
            'ram_hours' => ['month' => 1, 'week' => 1, 'day' => 1, 'hour' => 1],
            'memory_current' => 1,
            'memory_avg_month' => 1,
            'tasks_current' => 1,
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
            'io_read' => ['month' => 1, 'week' => 1, 'day' => 1, 'hour' => 1],
            'io_write' => ['month' => 2, 'week' => 2, 'day' => 2, 'hour' => 2],
            'cpu' => ['month' => 3, 'week' => 3, 'day' => 3, 'hour' => 3],
            'ram_hours' => ['month' => 4, 'week' => 4, 'day' => 4, 'hour' => 4],
            'memory_current' => 5,
            'memory_avg_month' => 6,
            'tasks_current' => 7,
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
