<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources.php';

class ResourceStatisticsTest extends TestCase
{
    public function testReadSnapshotMetricsFromPathUsesPersistedDayWindow(): void
    {
        $root = $this->pmssMakeTempDir('pmss-resource-stats-');
        $path = $root.'/.resourceData';
        $this->pmssWriteSerializedFixture($path, [
            'io_read' => $this->pmssBuildRawWindowMetric(0, 0, 11, 0), 'io_write' => $this->pmssBuildRawWindowMetric(0, 0, 22, 0), 'cpu' => $this->pmssBuildRawWindowMetric(0, 0, 33, 0),
            'memory' => $this->pmssBuildRawWindowMetric(0, 0, 44, 0), 'ram_hours' => $this->pmssBuildRawWindowMetric(0, 0, 5.5, 0), 'tasks' => $this->pmssBuildRawWindowMetric(0, 0, 6, 0), 'io_read_ops' => $this->pmssBuildRawWindowMetric(0, 0, 77, 0),
        ]);

        $stats = new \resourceStatistics();
        $metrics = $stats->readSnapshotMetricsFromPath($path);

        $this->assertEquals([
            'io_read' => 11.0,
            'io_write' => 22.0,
            'cpu' => 33.0,
            'memory' => 44.0,
            'ram_hours' => 5.5,
            'tasks' => 6.0,
            'io_read_ops' => 77.0,
            'io_write_ops' => 0.0,
        ], $metrics);
    }

    public function testCollectWindowResultsFromDataKeepsSnapshotFallbackInDayWindow(): void
    {
        $stats = new \resourceStatistics();
        $now = time();
        $results = $stats->collectWindowResultsFromData(implode("\n", [
            date('Y-m-d H:i:s', $now - 172800).' 999 999 9 9 900 9999 9',
            date('Y-m-d H:i:s', $now - 10800).' 100 200 1 2 300 1024 3',
            date('Y-m-d H:i:s', $now - 3600).' 400 500 4 5 600 2048 7',
        ]), ['day' => $now - 86400]);

        $this->assertTrue(is_array($results));
        $this->assertEquals(500.0, $results['raw']['io_read']['day']);
        $this->assertEquals(700.0, $results['raw']['io_write']['day']);
        $this->assertEquals(5.0, $results['raw']['io_read_ops']['day']);
        $this->assertEquals(7.0, $results['raw']['io_write_ops']['day']);
        $this->assertEquals(900.0, $results['raw']['cpu']['day']);
        $this->assertEquals(1536.0, $results['memory']['day']);
        $this->assertEquals(5.0, $results['tasks']['day']);
    }

    public function testParseLineValid(): void
    {
        $stats = new \resourceStatistics();
        $line = date('Y-m-d H:i:s').' 1024 2048 3000 4096 7';
        $parsed = $stats->parseLine($line);
        $this->assertTrue($parsed !== false);
        $this->assertEquals(1024.0, $parsed['io_read']);
        $this->assertEquals(0.0, $parsed['io_read_ops']);
        $this->assertEquals(7.0, $parsed['tasks']);
    }

    public function testParseLineWithOpsFields(): void
    {
        $stats = new \resourceStatistics();
        $line = date('Y-m-d H:i:s').' 1024 2048 12 34 3000 4096 7';
        $parsed = $stats->parseLine($line);
        $this->assertTrue($parsed !== false);
        $this->assertEquals(12.0, $parsed['io_read_ops']);
        $this->assertEquals(34.0, $parsed['io_write_ops']);
        $this->assertEquals(3000.0, $parsed['cpu']);
    }

    public function testParseLineIgnoresLegacyTrailingTokenOutsideOpsShape(): void
    {
        $stats = new \resourceStatistics();
        $line = date('Y-m-d H:i:s').' 1024 2048 3000 4096 7 ignored';
        $parsed = $stats->parseLine($line);
        $this->assertTrue($parsed !== false);
        $this->assertEquals(0.0, $parsed['io_read_ops']);
        $this->assertEquals(3000.0, $parsed['cpu']);
        $this->assertEquals(7.0, $parsed['tasks']);
    }

    public function testParseLineWithMemoryBreakdownFields(): void
    {
        $stats = new \resourceStatistics();
        $line = date('Y-m-d H:i:s').' 1024 2048 12 34 3000 4096 7 512 1024';
        $parsed = $stats->parseLine($line);
        $this->assertTrue($parsed !== false);
        $this->assertEquals(512.0, $parsed[array_values(\pmssResourceMemoryBreakdownFieldMap())[0]]);
        $this->assertEquals(1024.0, $parsed[array_values(\pmssResourceMemoryBreakdownFieldMap())[1]]);
    }

    public function testParseLineRejectsPartialMemoryBreakdownFields(): void
    {
        $stats = new \resourceStatistics();
        $line = date('Y-m-d H:i:s').' 1024 2048 12 34 3000 4096 7 512';
        $this->assertTrue($stats->parseLine($line) === false);
    }

    public function testParseLineRejectsMalformed(): void
    {
        $stats = new \resourceStatistics();
        $this->assertTrue($stats->parseLine('bad data') === false);
    }

    public function testParseLineRejectsNonNumeric(): void
    {
        $stats = new \resourceStatistics();
        $line = date('Y-m-d H:i:s').' 1 2 nope 4 5';
        $this->assertTrue($stats->parseLine($line) === false);
    }

    public function testParseLineRejectsMissingTokens(): void
    {
        $stats = new \resourceStatistics();
        $line = date('Y-m-d H:i:s').' 1 2 3';
        $this->assertTrue($stats->parseLine($line) === false);
    }
}
