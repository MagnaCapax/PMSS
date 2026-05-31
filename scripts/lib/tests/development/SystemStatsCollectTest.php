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

        // psiIo / psiMem expose an append-only slash-joined field set. The first
        // three (some_avg10/some_avg60/full_avg10) are the legacy node-collect
        // parser contract, kept byte-identical; appended after them:
        // some_avg300/full_avg60/full_avg300 (one-decimal floats) and
        // some_total/full_total (integer microseconds since boot). Whole field
        // still degrades to 'na' if /proc/pressure/* is unreadable.
        foreach (['psiIo', 'psiMem'] as $key) {
            $this->assertMatches(
                '#^(?:na|(?:na|[0-9]+\.[0-9])(?:/(?:na|[0-9]+\.[0-9])){5}/(?:na|[0-9]+)/(?:na|[0-9]+))$#',
                $stats[$key],
                $key.' should be 8 slash-joined PSI fields (6 avg + 2 total) or na'
            );
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

    public function testTopMemoryRowsRenderValidatedCompactValues(): void
    {
        $this->assertEquals(
            'mysqld:2.0G,php-fpm:1.5M,cron:512K',
            \pmssSystemStatsTopMemoryFromPsRows([
                'mysqld 2097152',
                'php-fpm 1536',
                'cron 512',
            ])
        );
    }

    public function testTopMemoryRowsSkipMalformedBoundaryInput(): void
    {
        $this->assertEquals(
            'nginx:2.0M',
            \pmssSystemStatsTopMemoryFromPsRows([
                '',
                'php-fpm not-a-number',
                'bad:name 1024',
                'bad,name 1024',
                "bad\tname 1024",
                'nginx 2048',
            ])
        );
    }

    public function testTopMemoryRowsReturnNaWhenNoValidRowsRemain(): void
    {
        $this->assertEquals('na', \pmssSystemStatsTopMemoryFromPsRows([
            'only-command',
            'bad:name 1024',
            'php-fpm -1',
        ]));
    }

    public function testTopMemoryProcessesReturnNaWhenCommandFails(): void
    {
        $summary = \pmssSystemStatsTopMemoryProcesses(static function (array &$output, int &$rc): void {
            $output = ['php-fpm 2048'];
            $rc = 1;
        });

        $this->assertEquals('na', $summary);
    }

    public function testTopMemoryProcessesUseRunnerOutputWhenCommandPasses(): void
    {
        $summary = \pmssSystemStatsTopMemoryProcesses(static function (array &$output, int &$rc): void {
            $output = ['php-fpm 2048'];
            $rc = 0;
        });

        $this->assertEquals('php-fpm:2.0M', $summary);
    }
}
