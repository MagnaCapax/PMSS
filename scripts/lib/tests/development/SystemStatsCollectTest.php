<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/systemStats.php';

class SystemStatsCollectTest extends TestCase
{
    public function testCollectReturnsExpectedMetricKeys(): void
    {
        $stats = \pmssSystemStatsCollect();

        $this->assertEquals(
            [
                'load',
                'cpuIowait',
                'memTotal',
                'memFree',
                'memCache',
                'memBuffers',
                'swapTotal',
                'swapFree',
                'diskBusy',
                'iopingRoot',
                'iopingHome',
                'topMem',
                'psiIo',
                'psiMem',
            ],
            array_keys($stats)
        );
    }

    public function testCollectValuesRemainStringFormatted(): void
    {
        $stats = \pmssSystemStatsCollect();

        foreach ($stats as $key => $value) {
            $this->assertTrue(is_string($value), $key.' should stay string formatted');
            $this->assertTrue($value !== '', $key.' should not be empty');
        }

        $this->assertMatches('/^(?:na|[0-9.]+),(?:na|[0-9.]+),(?:na|[0-9.]+)$/', $stats['load']);
        $this->assertMatches('/^[0-9]+\.[0-9]$/', $stats['cpuIowait']);
        $this->assertMatches('/^[0-9]+\.[0-9]$/', $stats['diskBusy']);

        foreach (['memTotal', 'memFree', 'memCache', 'memBuffers', 'swapTotal', 'swapFree'] as $key) {
            $this->assertMatches('/^(?:[0-9]+\.[0-9][GM]|[0-9]+K)$/', $stats[$key], $key.' should keep compact memory units');
        }

        foreach (['psiIo', 'psiMem'] as $key) {
            $this->assertMatches('/^(?:na|[0-9]+\.[0-9])$/', $stats[$key], $key.' should stay numeric or na');
        }

        foreach (['iopingRoot', 'iopingHome'] as $key) {
            $this->assertMatches('/^(?:na|[0-9]+\.[0-9]ms)$/', $stats[$key], $key.' should stay latency or na');
        }
    }

    public function testCollectUsesShortSamplingWindowInTestMode(): void
    {
        $start = microtime(true);
        \pmssSystemStatsCollect();
        $elapsed = microtime(true) - $start;

        $this->assertTrue($elapsed < 0.9, 'Expected PMSS_TEST_MODE sampling window to stay short, got '.$elapsed.'s');
    }
}
