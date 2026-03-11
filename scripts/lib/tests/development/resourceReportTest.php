<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/report.php';

class ResourceReportTest extends TestCase
{
    /** @var string */
    private $statsDir;

    public function setUp(): void
    {
        $this->statsDir = sys_get_temp_dir().'/pmss-resource-report-'.mt_rand(1000, 999999);
        @mkdir($this->statsDir, 0755, true);
    }

    public function tearDown(): void
    {
        if (!is_dir($this->statsDir)) {
            return;
        }
        foreach ((array) glob($this->statsDir.'/*') as $file) {
            @unlink($file);
        }
        @rmdir($this->statsDir);
    }

    public function testBuildReportAggregatesRowsAndTotals(): void
    {
        $this->writeUserData('alice', $this->buildStatsPayload([
            'io_read' => ['month' => 1000, 'week' => 100, 'day' => 10, 'hour' => 1],
            'io_write' => ['month' => 2000, 'week' => 200, 'day' => 20, 'hour' => 2],
            'io_read_ops' => ['month' => 3000, 'week' => 300, 'day' => 30, 'hour' => 3],
            'io_write_ops' => ['month' => 4000, 'week' => 400, 'day' => 40, 'hour' => 4],
            'cpu' => ['month' => 5000, 'week' => 500, 'day' => 50, 'hour' => 5],
            'ram_hours' => ['month' => 6000, 'week' => 600, 'day' => 60, 'hour' => 6],
            'memory_current' => 700,
            'memory_avg_month' => 70,
            'tasks_current' => 7,
        ]));
        $this->writeUserData('bob', $this->buildStatsPayload([
            'io_read' => ['month' => 11, 'week' => 12, 'day' => 13, 'hour' => 14],
            'io_write' => ['month' => 21, 'week' => 22, 'day' => 23, 'hour' => 24],
            'io_read_ops' => ['month' => 31, 'week' => 32, 'day' => 33, 'hour' => 34],
            'io_write_ops' => ['month' => 41, 'week' => 42, 'day' => 43, 'hour' => 44],
            'cpu' => ['month' => 51, 'week' => 52, 'day' => 53, 'hour' => 54],
            'ram_hours' => ['month' => 61, 'week' => 62, 'day' => 63, 'hour' => 64],
            'memory_current' => 71,
            'memory_avg_month' => 72,
            'tasks_current' => 73,
        ]));

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
        $payload = $this->buildStatsPayload([
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
        $payload = $this->buildStatsPayload([
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

    public function testBuildJsonPayloadKeepsExpectedKeyMapping(): void
    {
        $rows = [
            'alice' => [
                'io_read' => ['month' => 1.0],
                'io_write' => ['month' => 2.0],
                'io_read_ops' => ['month' => 3.0],
                'io_write_ops' => ['month' => 4.0],
                'cpu' => ['month' => 5.0],
                'memory_current' => 6.0,
                'memory_avg_month' => 7.0,
                'ram_hours' => ['month' => 8.0],
                'tasks_current' => 9.0,
            ],
        ];
        $totals = [
            'io_read' => ['month' => 10.0],
            'io_write' => ['month' => 11.0],
            'io_read_ops' => ['month' => 12.0],
            'io_write_ops' => ['month' => 13.0],
            'cpu' => ['month' => 14.0],
            'memory_current' => 15.0,
            'memory_avg_month' => 16.0,
            'ram_hours' => ['month' => 17.0],
            'tasks_current' => 18.0,
        ];

        $payload = \pmssResourceBuildJsonPayload($rows, $totals, ['ghost']);

        $this->assertEquals(6.0, $payload['users']['alice']['memory']['current']);
        $this->assertEquals(8.0, $payload['users']['alice']['ram_hours']['month']);
        $this->assertEquals(15.0, $payload['totals']['memory']['current']);
        $this->assertEquals(18.0, $payload['totals']['tasks']['current']);
        $this->assertEquals(['ghost'], $payload['missing']);
    }

    public function testBuildJsonPayloadHandlesEmptyUserRows(): void
    {
        $totals = [
            'io_read' => ['month' => 10.0],
            'io_write' => ['month' => 11.0],
            'io_read_ops' => ['month' => 12.0],
            'io_write_ops' => ['month' => 13.0],
            'cpu' => ['month' => 14.0],
            'memory_current' => 15.0,
            'memory_avg_month' => 16.0,
            'ram_hours' => ['month' => 17.0],
            'tasks_current' => 18.0,
        ];

        $payload = \pmssResourceBuildJsonPayload([], $totals, []);

        $this->assertEquals([], $payload['users']);
        $this->assertEquals(15.0, $payload['totals']['memory']['current']);
        $this->assertEquals(18.0, $payload['totals']['tasks']['current']);
    }

    public function testBuildJsonPayloadDropsUnexpectedSourceKeys(): void
    {
        $rows = [
            'alice' => [
                'io_read' => ['month' => 1.0],
                'io_write' => ['month' => 2.0],
                'io_read_ops' => ['month' => 3.0],
                'io_write_ops' => ['month' => 4.0],
                'cpu' => ['month' => 5.0],
                'memory_current' => 6.0,
                'memory_avg_month' => 7.0,
                'ram_hours' => ['month' => 8.0],
                'tasks_current' => 9.0,
                'ignored_field' => ['month' => 999.0],
            ],
        ];
        $totals = $rows['alice'] + ['ignored_total' => 10.0];

        $payload = \pmssResourceBuildJsonPayload($rows, $totals, []);

        $this->assertTrue(!isset($payload['users']['alice']['ignored_field']));
        $this->assertTrue(!isset($payload['totals']['ignored_total']));
    }

    private function writeUserData(string $user, array $data): void
    {
        @file_put_contents($this->statsDir.'/'.$user, serialize($data));
    }

    private function buildStatsPayload(array $values): array
    {
        return [
            'io_read' => ['raw' => $values['io_read']],
            'io_write' => ['raw' => $values['io_write']],
            'io_read_ops' => ['raw' => $values['io_read_ops'] ?? []],
            'io_write_ops' => ['raw' => $values['io_write_ops'] ?? []],
            'cpu' => ['raw' => $values['cpu']],
            'memory' => [
                'current' => $values['memory_current'],
                'raw' => ['month' => $values['memory_avg_month']],
            ],
            'ram_hours' => ['raw' => $values['ram_hours']],
            'tasks' => ['current' => $values['tasks_current']],
        ];
    }
}
