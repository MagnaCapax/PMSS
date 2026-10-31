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
        $this->statsDir = $this->pmssEnsureDir($this->runtimeDir.'/resourceStats');
        $this->markerPath = $this->runtimeDir.'/wakeup-marker';
    }

    public function testBuildReportRejectsSerializedObjectsWithoutWakeup(): void
    {
        $this->pmssWriteSerializedFixture($this->statsDir.'/fixtur01', new ResourceReportSafetyWakeupProbe($this->markerPath));

        $report = \pmssResourceBuildReport($this->statsDir, ['fixtur01']);

        $this->assertEquals(['fixtur01'], $report['missing']);
        $this->assertEquals([], $report['rows']);
        $this->assertTrue(!file_exists($this->markerPath));
    }

    public function testStoredWindowRejectsNonFiniteValuesAcrossConsumers(): void
    {
        $metrics = array_merge(\ResourceStatsAccumulator::RAW_METRICS, \ResourceStatsAccumulator::AVERAGE_METRICS);
        $payload = array_fill_keys($metrics, ['raw' => $this->pmssBuildWindowValues(1)]);
        foreach ($metrics as $metric) {
            foreach ([INF, -INF, NAN, '1e9999', '-1e9999'] as $invalid) {
                $data = $payload;
                $data[$metric]['raw']['day'] = $invalid;
                $this->assertSame(null, \pmssResourceStoredPayloadWindowValue($data, $metric, 'day'));
                $this->assertSame(null, \pmssResourceStoredPayloadWindowMetrics($data, 'day'));
                if (!in_array($metric, \ResourceStatsAccumulator::RAW_METRICS, true)) continue;
                $this->pmssWriteSerializedFixture($this->statsDir.'/fixtur01', $data);
                $report = \pmssResourceBuildReport($this->statsDir, ['fixtur01']);
                $this->pmssAssertArraySubsetSame([
                    'missing' => ['fixtur01'], 'rows' => [], 'totals' => \pmssResourceReportTemplate(),
                ], $report);
                $this->assertTrue(is_string(json_encode($report)));
            }
        }
    }

    public function testStoredWindowPreservesFiniteValuesAndMissingDefaults(): void
    {
        foreach ([0, -1, 1.5, '00012', '2.5e2', PHP_FLOAT_MAX, -PHP_FLOAT_MAX] as $value) {
            $data = ['cpu' => ['raw' => ['day' => $value]]];
            $this->assertSame((float) $value, \pmssResourceStoredPayloadWindowValue($data, 'cpu', 'day'));
        }
        foreach ([null, '', 'bad', [], false] as $value) {
            $data = ['cpu' => ['raw' => ['day' => $value]]];
            $this->assertSame(null, \pmssResourceStoredPayloadWindowValue($data, 'cpu', 'day'));
        }
        $this->assertSame(null, \pmssResourceStoredPayloadWindowValue([], 'cpu', 'day'));
        foreach (['io_read_ops', 'io_write_ops'] as $metric) {
            $this->assertSame(0.0, \pmssResourceStoredPayloadWindowValue([], $metric, 'day'));
            $this->assertSame(0.0, \pmssResourceStoredPayloadWindowValue([$metric => ['raw' => ['day' => null]]], $metric, 'day'));
        }
    }

    public function testStoredWindowRejectsMalformedContainers(): void
    {
        $metrics = array_merge(\ResourceStatsAccumulator::RAW_METRICS, \ResourceStatsAccumulator::AVERAGE_METRICS);
        $payload = array_fill_keys($metrics, ['raw' => $this->pmssBuildWindowValues(1)]);
        foreach ($metrics as $metric) {
            foreach ([false, true, 0, 1.5, 'bad', new \stdClass()] as $invalid) {
                foreach ([$invalid, ['raw' => $invalid]] as $container) {
                    $data = $payload;
                    $data[$metric] = $container;
                    $this->assertSame(null, \pmssResourceStoredPayloadWindowValue($data, $metric, 'day'));
                    $this->assertSame(null, \pmssResourceStoredPayloadWindowMetrics($data, 'day'));
                }
            }
        }
    }

    public function testBuildReportRejectsNestedObjectsWithoutWakeup(): void
    {
        $metrics = array_merge(\ResourceStatsAccumulator::RAW_METRICS, \ResourceStatsAccumulator::AVERAGE_METRICS);
        $payload = array_fill_keys($metrics, ['raw' => $this->pmssBuildWindowValues(1)]);
        $probe = new ResourceReportSafetyWakeupProbe($this->markerPath);
        foreach ($metrics as $metric) {
            // Reports consume every metric container, but only memory's average window.
            $containers = [$probe];
            if ($metric !== 'tasks') $containers[] = ['raw' => $probe];
            foreach ($containers as $container) {
                $data = $payload;
                $data[$metric] = $container;
                $this->pmssWriteSerializedFixture($this->statsDir.'/fixtur01', $data);
                $report = \pmssResourceBuildReport($this->statsDir, ['fixtur01']);
                $this->pmssAssertArraySubsetSame([
                    'missing' => ['fixtur01'], 'rows' => [], 'totals' => \pmssResourceReportTemplate(),
                ], $report);
                $this->assertTrue(!file_exists($this->markerPath));
            }
        }
    }

    public function testReportRejectsMalformedContainersAndKeepsValidUsers(): void
    {
        $payload = array_fill_keys(\ResourceStatsAccumulator::RAW_METRICS, ['raw' => $this->pmssBuildWindowValues(1)]);
        $validRow = \pmssResourceReportTemplate();
        foreach (\ResourceStatsAccumulator::RAW_METRICS as $metric) $validRow[$metric] = $this->pmssBuildWindowValues(1.0);
        $this->pmssWriteSerializedFixture($this->statsDir.'/fixtur02', $payload);
        foreach ([false, true, 0, 1.5, 'bad', new \stdClass()] as $invalid) {
            foreach ([
                ['memory' => $invalid],
                ['memory' => ['raw' => $invalid]],
                ['tasks' => $invalid],
            ] as $optional) {
                $data = $payload + $optional;
                $this->assertSame(null, \pmssResourceStoredPayloadReportRow($data));
                $this->pmssWriteSerializedFixture($this->statsDir.'/fixtur01', $data);
                $report = \pmssResourceBuildReport($this->statsDir, ['fixtur01', 'fixtur02']);
                $this->pmssAssertArraySubsetSame([
                    'missing' => ['fixtur01'], 'rows' => ['fixtur02' => $validRow], 'totals' => $validRow,
                ], $report);
                $this->assertTrue(is_string(json_encode($report)));
            }
        }
    }

    public function testReportPreservesLegacyOptionalConversionsAndDefaults(): void
    {
        $payload = array_fill_keys(\ResourceStatsAccumulator::RAW_METRICS, ['raw' => $this->pmssBuildWindowValues(1)]);
        $expected = \pmssResourceReportTemplate();
        foreach (\ResourceStatsAccumulator::RAW_METRICS as $metric) $expected[$metric] = $this->pmssBuildWindowValues(1.0);
        foreach ([0, -1, 1.5, '00012', '2.5e2', PHP_FLOAT_MAX, -PHP_FLOAT_MAX,
            INF, -INF, '1e9999', '-1e9999', false, true, '', 'bad', [],
        ] as $value) {
            $data = $payload + ['memory' => ['current' => $value, 'raw' => ['month' => $value]], 'tasks' => ['current' => $value]];
            $row = $expected;
            $row['memory'] = ['current' => (float) $value, 'avg_month' => (float) $value];
            $row['tasks']['current'] = (float) $value;
            $this->assertSame($row, \pmssResourceStoredPayloadReportRow($data));
        }
        foreach ([[], ['memory' => null, 'tasks' => null], ['memory' => [], 'tasks' => []],
            ['memory' => ['current' => null, 'raw' => null], 'tasks' => ['current' => null]],
            ['memory' => ['raw' => ['month' => null]]],
        ] as $optional) {
            $this->assertSame($expected, \pmssResourceStoredPayloadReportRow($payload + $optional));
        }
        foreach (['io_read_ops', 'io_write_ops'] as $metric) {
            foreach ([null, [], ['raw' => null], ['raw' => []], ['raw' => ['day' => null]]] as $container) {
                $this->assertSame(0.0, \pmssResourceStoredPayloadWindowValue([$metric => $container], $metric, 'day'));
            }
        }
    }

    public function testBuildReportSkipsInvalidUserTraversalKeys(): void
    {
        $this->pmssWriteSerializedFixture($this->runtimeDir.'/outside-stats', $this->pmssBuildResourceStatsPayloadFromValues([
            'io_read' => $this->pmssBuildWindowValues(1),
            'io_write' => $this->pmssBuildWindowValues(2),
            'io_read_ops' => $this->pmssBuildWindowValues(3),
            'io_write_ops' => $this->pmssBuildWindowValues(4),
            'cpu' => $this->pmssBuildWindowValues(5),
            'ram_hours' => $this->pmssBuildWindowValues(6),
            'memory_current' => 7,
            'memory_avg_month' => 8,
            'tasks_current' => 9,
        ]));

        $report = \pmssResourceBuildReport($this->statsDir, ['../outside-stats']);

        $this->assertEquals([], $report['missing']);
        $this->assertEquals([], $report['rows']);
    }
}
