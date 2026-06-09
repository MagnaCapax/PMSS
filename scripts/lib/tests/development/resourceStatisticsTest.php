<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources.php';

class ResourceStatisticsTest extends TestCase
{
    private function resourceLine(int $timestamp, string $payload): string
    {
        return date('Y-m-d H:i:s', $timestamp).' '.$payload;
    }

    public function testReadSnapshotMetricsFromPathUsesPersistedDayWindow(): void
    {
        $root = $this->pmssMakeTempDir('pmss-resource-stats-');
        $path = $root.'/.resourceData';
        $this->pmssWriteSerializedFixture($path, $this->pmssBuildResourceStatsPayloadFromValues([
            'io_read' => $this->pmssBuildWindowValues(0, 0, 11, 0),
            'io_write' => $this->pmssBuildWindowValues(0, 0, 22, 0),
            'cpu' => $this->pmssBuildWindowValues(0, 0, 33, 0),
            'memory' => $this->pmssBuildWindowValues(0, 0, 44, 0),
            'ram_hours' => $this->pmssBuildWindowValues(0, 0, 5.5, 0),
            'tasks' => $this->pmssBuildWindowValues(0, 0, 6, 0),
            'io_read_ops' => $this->pmssBuildWindowValues(0, 0, 77, 0),
        ]));

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

    public function testGetDataRejectsTraversalUsernames(): void
    {
        $root = $this->pmssMakeTempDir('pmss-resource-getdata-');
        file_put_contents($root.'/outside', "should-not-read\n");

        $stats = new \resourceStatistics(['resource_dir' => $root]);

        $this->assertSame('', $stats->getData('../outside', 1));
    }

    public function testGetDataAcceptsWwwData(): void
    {
        $root = $this->pmssMakeTempDir('pmss-resource-getdata-www-');
        file_put_contents($root.'/www-data', "first\nsecond\n");

        $stats = new \resourceStatistics(['resource_dir' => $root]);

        $this->assertSame('second', $stats->getData('www-data', 1));
    }

    public function testResourceTailLineCountBoundsShellBoundary(): void
    {
        $max = (int) constant('PMSS_RESOURCE_LOG_TAIL_LINES_MAX');

        foreach ([0, -10, 'not-a-number'] as $value) {
            $this->assertSame(1, \pmssResourceTailLineCount($value));
        }
        $this->assertSame(42, \pmssResourceTailLineCount('42'));
        $this->assertSame($max, \pmssResourceTailLineCount($max + 50000));
    }

    public function testGetDataRejectsUnsafeResourceDirectories(): void
    {
        foreach (['relative-resource-dir', "/tmp/pmss-resource\0dir"] as $resourceDir) {
            $stats = new \resourceStatistics(['resource_dir' => $resourceDir]);

            $this->assertSame('', $stats->getData('www-data', 1), 'Unsafe resource dir should not be tailed: '.var_export($resourceDir, true));
        }
    }

    public function testGetDataRejectsSymlinkedResourceDirectory(): void
    {
        $root = $this->pmssMakeTempDir('pmss-resource-getdata-link-');
        $target = $root.'/target';
        @mkdir($target, 0700);
        file_put_contents($target.'/www-data', "should-not-read\n");
        $link = $root.'/link';
        $this->pmssCreateSymlinkOrSkip($target, $link);

        $stats = new \resourceStatistics(['resource_dir' => $link]);

        $this->assertSame('', $stats->getData('www-data', 1));
    }

    public function testResourceResultsWindowMetricsValidatesSnapshotFallbackShape(): void
    {
        $results = [
            'memory' => ['day' => '1024'],
            'tasks' => ['day' => '3'],
            'raw' => [
                'io_read' => ['day' => '1'],
                'io_write' => ['day' => '2'],
                'io_read_ops' => ['day' => '3'],
                'io_write_ops' => ['day' => '4'],
                'cpu' => ['day' => '5'],
                'ram_hours' => ['day' => '6.5'],
            ],
        ];

        $this->assertEquals([
            'memory' => 1024.0,
            'tasks' => 3.0,
            'io_read' => 1.0,
            'io_write' => 2.0,
            'io_read_ops' => 3.0,
            'io_write_ops' => 4.0,
            'cpu' => 5.0,
            'ram_hours' => 6.5,
        ], \pmssResourceResultsWindowMetrics($results, 'day'));

        $badCpu = $results;
        $badCpu['raw']['cpu']['day'] = 'bad';
        $this->assertSame(null, \pmssResourceResultsWindowMetrics($badCpu, 'day'));

        $missingRamHours = $results;
        unset($missingRamHours['raw']['ram_hours']['day']);
        $this->assertSame(null, \pmssResourceResultsWindowMetrics($missingRamHours, 'day'));
    }

    public function testCollectWindowResultsFromDataKeepsSnapshotFallbackInDayWindow(): void
    {
        $stats = new \resourceStatistics();
        $now = time();
        $results = $stats->collectWindowResultsFromData(implode("\n", [
            $this->resourceLine($now - 172800, '999 999 9 9 900 9999 9'),
            $this->resourceLine($now - 10800, '100 200 1 2 300 1024 3'),
            $this->resourceLine($now - 3600, '400 500 4 5 600 2048 7'),
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

    public function testResourceLogLineCodecMatchesStableSnapshot(): void
    {
        $stats = new \resourceStatistics();
        $timestamp = strtotime('2026-05-16 12:34:56');
        $parts = \pmssResourceLogLineParts(
            ['io_read' => 1024, 'io_write' => 2048, 'io_read_ops' => 12, 'io_write_ops' => 34],
            ['cpu_nsec' => 3000, 'memory' => 4096, 'tasks' => 7, 'memory_anon' => 512, 'memory_file' => 1024]
        );

        $this->assertEquals(['1024', '2048', '12', '34', '3000', '4096', '7', '512', '1024'], $parts);
        $this->assertEquals([
            'timestamp' => $timestamp,
            'io_read' => 1024.0,
            'io_write' => 2048.0,
            'io_read_ops' => 12.0,
            'io_write_ops' => 34.0,
            'cpu' => 3000.0,
            'memory' => 4096.0,
            'tasks' => 7.0,
            'memory_anon' => 512.0,
            'memory_file' => 1024.0,
        ], $stats->parseLine($this->resourceLine($timestamp, implode(' ', $parts))));
    }

    public function testParseLineValid(): void
    {
        $stats = new \resourceStatistics();
        $timestamp = strtotime('2026-05-16 12:34:56');
        $base = [
            'timestamp' => $timestamp,
            'io_read' => 1024.0,
            'io_write' => 2048.0,
            'io_read_ops' => 0.0,
            'io_write_ops' => 0.0,
            'cpu' => 3000.0,
            'memory' => 4096.0,
            'tasks' => 7.0,
        ];

        foreach ([
            'legacy' => ['1024 2048 3000 4096 7', $base],
            'legacy_extra' => ['1024 2048 3000 4096 7 ignored', $base],
            'ops' => ['1024 2048 12 34 3000 4096 7', array_merge($base, ['io_read_ops' => 12.0, 'io_write_ops' => 34.0])],
            'memory_breakdown' => ['1024 2048 12 34 3000 4096 7 512 1024', array_merge($base, ['io_read_ops' => 12.0, 'io_write_ops' => 34.0, 'memory_anon' => 512.0, 'memory_file' => 1024.0])],
            'memory_breakdown_extra' => ['1024 2048 12 34 3000 4096 7 512 1024 ignored', array_merge($base, ['io_read_ops' => 12.0, 'io_write_ops' => 34.0, 'memory_anon' => 512.0, 'memory_file' => 1024.0])],
        ] as $label => $case) {
            $this->assertEquals($case[1], $stats->parseLine($this->resourceLine($timestamp, $case[0])), $label);
        }
    }

    public function testParseLineRejectsMalformed(): void
    {
        $stats = new \resourceStatistics();
        $timestamp = time();

        foreach ([
            'bad data',
            $this->resourceLine($timestamp, '1 2 nope 4 5'),
            $this->resourceLine($timestamp, '1 2 3'),
            $this->resourceLine($timestamp, '1024 2048 12 34 3000 4096 7 512'),
            'not-a-date 12:34:56 1024 2048 3000 4096 7',
        ] as $line) {
            $this->assertTrue($stats->parseLine($line) === false, $line);
        }
    }
}
