<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/resources/log.php';

/** Lock resource text records independently of their collection and report consumers. */
class ResourceLogCodecCharacterizationTest extends TestCase
{
    public function testMemoryFieldsKeepPrefixAndOrder(): void
    {
        foreach (['memory_', '', 'custom_', '0', 'memory_anon'] as $prefix) {
            $this->assertSame(['anon' => $prefix.'anon', 'file' => $prefix.'file'],
                \pmssResourceMemoryBreakdownFieldMap($prefix));
        }
    }

    public function testHistoricalFieldLayoutsAndIgnoredExtensions(): void
    {
        $legacy = ['io_read', 'io_write', 'cpu', 'memory', 'tasks'];
        $current = ['io_read', 'io_write', 'io_read_ops', 'io_write_ops', 'cpu', 'memory', 'tasks'];
        $extended = array_merge($current, ['memory_anon', 'memory_file']);
        foreach ([-1 => null, 0 => null, 4 => null, 5 => $legacy, 6 => $legacy,
            7 => $current, 8 => null, 9 => $extended, 10 => $extended, 100 => $extended] as $count => $expected) {
            $this->assertSame($expected, \pmssResourceLogPayloadFields($count));
        }
    }

    public function testWriterUsesDeltasAndCurrentValuesFromTheirOwnInputs(): void
    {
        $delta = ['io_read' => 11, 'io_write' => 22, 'io_read_ops' => 33, 'io_write_ops' => 44,
            'cpu_nsec' => 55, 'memory' => 999, 'tasks' => 999, 'memory_anon' => 999, 'memory_file' => 999];
        $state = ['io_read' => 999, 'io_write' => 999, 'io_read_ops' => 999, 'io_write_ops' => 999,
            'cpu_nsec' => 999, 'memory' => 66, 'tasks' => 77];
        $base = ['11', '22', '33', '44', '55', '66', '77'];
        foreach ([[], ['memory_anon' => 88], ['memory_file' => 99],
            ['memory_anon' => null, 'memory_file' => 99], ['memory_anon' => 88, 'memory_file' => null]] as $partial) {
            $this->assertSame($base, \pmssResourceLogLineParts($delta, $state + $partial));
        }
        $this->assertSame(array_merge($base, ['0', '0']),
            \pmssResourceLogLineParts($delta, $state + ['memory_anon' => 0, 'memory_file' => 0]));
        $this->assertSame(array_merge($base, ['88', '99']),
            \pmssResourceLogLineParts($delta, $state + ['memory_anon' => 88, 'memory_file' => 99]));
        $this->assertSame(array_fill(0, 7, '0'), \pmssResourceLogLineParts([], []));
    }

    public function testParserRetainsTypesKeyOrderAndLegacyDefaults(): void
    {
        $stamp = '2026-01-02 03:04:05';
        $legacy = ['timestamp' => strtotime($stamp), 'io_read' => 1.0, 'io_write' => 2.0,
            'io_read_ops' => 0.0, 'io_write_ops' => 0.0, 'cpu' => 3.0, 'memory' => 4.0, 'tasks' => 5.0];
        $current = array_merge($legacy, ['io_read_ops' => 3.0, 'io_write_ops' => 4.0,
            'cpu' => 5.0, 'memory' => 6.0, 'tasks' => 7.0]);
        foreach ([
            ['1 2 3 4 5', $legacy], ['1 2 3 4 5 ignored', $legacy],
            ['1 2 3 4 5 6 7', $current],
            ['1 2 3 4 5 6 7 8 9', $current + ['memory_anon' => 8.0, 'memory_file' => 9.0]],
            ['1 2 3 4 5 6 7 8 9 ignored', $current + ['memory_anon' => 8.0, 'memory_file' => 9.0]],
        ] as [$payload, $expected]) {
            $this->assertSame($expected, \pmssResourceLogLineParse($stamp.' '.$payload));
            $this->assertSame($expected, \pmssResourceLogLineParse("\t".$stamp."\t".$payload."\r\n"));
        }
    }

    public function testEveryConsumedPositionStillRejectsInvalidNumbers(): void
    {
        foreach ([5, 7, 9] as $count) {
            for ($offset = 0; $offset < $count; $offset++) {
                foreach (['-1', '+1', '1.0', '1e2', 'invalid', str_repeat('9', 400)] as $invalid) {
                    $parts = array_fill(0, $count, '1');
                    $parts[$offset] = $invalid;
                    $this->assertSame(false, \pmssResourceLogLineParse('2026-01-02 03:04:05 '.implode(' ', $parts)));
                }
            }
        }
        foreach (['', 'invalid-date 03:04:05 1 2 3 4 5', '2026-01-02 03:04:05 1 2 3 4',
            '2026-01-02 03:04:05 1 2 3 4 5 6 7 8'] as $line) {
            $this->assertSame(false, \pmssResourceLogLineParse($line));
        }
    }

    public function testStateOrderAndSourceReseedingKeepCpuDeltas(): void
    {
        $path = $this->pmssMakeTempDir('pmss-resource-codec-').'/state.json';
        $counters = ['memory' => 66, 'tasks' => 77, 'io_read' => 11, 'io_write' => 22,
            'io_read_ops' => 33, 'io_write_ops' => 44, 'cpu_nsec' => 55,
            'memory_anon' => 88, 'memory_file' => 99, 'io_source' => 'bfq', 'io_scope' => 'recursive'];
        $first = \pmssResourceLogUpdateState($path, $counters);
        $this->assertSame(['memory', 'tasks', 'ts', 'io_read', 'io_write', 'io_read_ops', 'io_write_ops',
            'cpu_nsec', 'memory_anon', 'memory_file', 'io_source', 'io_scope'], array_keys($first['state']));
        $this->assertSame(['io_read' => 0, 'io_write' => 0, 'io_read_ops' => 0, 'io_write_ops' => 0,
            'cpu_nsec' => 55], $first['delta']);
        $next = array_merge($counters, ['io_read' => 111, 'io_write' => 122, 'io_read_ops' => 133,
            'io_write_ops' => 144, 'cpu_nsec' => 105]);
        $second = \pmssResourceLogUpdateState($path, $next);
        $this->assertSame(['io_read' => 100, 'io_write' => 100, 'io_read_ops' => 100, 'io_write_ops' => 100,
            'cpu_nsec' => 50], $second['delta']);
        $third = \pmssResourceLogUpdateState($path, array_merge($counters, ['io_source' => 'throttle', 'cpu_nsec' => 155]));
        $this->assertSame(['io_read' => 0, 'io_write' => 0, 'io_read_ops' => 0, 'io_write_ops' => 0,
            'cpu_nsec' => 50], $third['delta']);
    }

    public function testCodecAndLiveReaderLoadWithoutResourceAggregation(): void
    {
        foreach (['scripts/lib/resources/logCodec.php', 'scripts/lib/liveStats.php'] as $path) {
            $this->assertSame([false, false, true], $this->pmssRunRepoInlinePhpRequireJson($path,
                'echo json_encode([class_exists("resourceStatistics", false), class_exists("ResourceStatsAccumulator", false), function_exists("pmssResourceLogLineParse")]);'));
        }
    }
}
