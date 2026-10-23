<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/runtime.php';

/** Exercise latency parsing without executing a probe or reading host metrics. */
class IopingOutputSafetyTest extends TestCase
{
    public function testSummaryPreservesUnitsWhitespaceAndZero(): void
    {
        foreach (['0 ms' => 0.0, '125 us' => 0.125, '1.25ms' => 1.25,
            '0.5 s' => 500.0, '002.50 MS' => 2.5, "\t2.5\tms" => 2.5, '.5 ms' => 0.5, '1. ms' => 1.0] as $field => $expected) {
            $this->assertSame($expected, \pmssIopingAverageMsFromOutput($this->summary($field)));
        }
    }

    public function testSummaryRejectsMalformedAndOverflowingAverages(): void
    {
        foreach (['', '. ms', '1..2 ms', '1.2.3 ms', '-1 ms', 'NaN ms', 'INF ms', '1e3 ms',
            str_repeat('9', 400).' ms', str_repeat('9', 306).' s'] as $field) {
            $this->assertSame(null, \pmssIopingAverageMsFromOutput($this->summary($field)), $field);
        }
        $this->assertSame(null, \pmssIopingAverageMsFromOutput('no summary'));
    }

    public function testSamplesPreserveOrderUnitsAndDenseKeys(): void
    {
        $raw = "4 KiB time=125 us\n4 KiB time=1.25ms\n4 KiB time = 0.5 S\n4 KiB time=0 ms\ntime=.5 ms\ntime=1. ms\n";
        $this->assertSame([0.125, 1.25, 500.0, 0.0, 0.5, 1.0], \pmssIopingSamplesMsFromOutput($raw));
        $this->assertSame([], \pmssIopingSamplesMsFromOutput($this->summary('1.5 ms')));
    }

    public function testSamplesSkipInvalidValuesWithoutDiscardingValidNeighbours(): void
    {
        foreach (['', '.', '1..2', '1.2.3', '-1', 'NaN', 'INF', '1e3', str_repeat('9', 400)] as $value) {
            $raw = "time=1 ms\ntime={$value} ms\ntime=3 ms\n";
            $this->assertSame([1.0, 3.0], \pmssIopingSamplesMsFromOutput($raw), $value);
        }
        $this->assertSame([2.0], \pmssIopingSamplesMsFromOutput('time='.str_repeat('9', 306)." s\ntime=2 ms"));
        $this->assertSame([], \pmssIopingSamplesMsFromOutput('time=1..2 ms'));
    }

    public function testMedianRetainsOrdinaryResultsAndInputOrder(): void
    {
        foreach ([[[], null], [[4.0], 4.0], [[3.0, 1.0], 2.0], [[3.0, 1.0, 2.0], 2.0],
            [[4.0, 1.0, 3.0, 2.0], 2.5], [[0.0, 0.0], 0.0]] as [$values, $expected]) {
            $before = $values;
            $this->assertSame($expected, \pmssIopingMedianMsFromSamples($values));
            $this->assertSame($before, $values);
        }
    }

    public function testFiniteLargeSamplesKeepAFiniteMedian(): void
    {
        $decimal = '1'.str_repeat('0', 308);
        $samples = \pmssIopingSamplesMsFromOutput("time={$decimal} ms\ntime={$decimal} ms");
        $this->assertSame([1.0e308, 1.0e308], $samples);
        $this->assertSame(1.0e308, \pmssIopingMedianMsFromSamples($samples));
        $this->assertSame(1.0e308, \pmssIopingAverageMsFromOutput($this->summary($decimal.' ms')));
    }

    private function summary(string $average): string
    {
        return 'min/avg/max/mdev = 1 ms / '.$average.' / 3 ms / 0 ms';
    }
}
