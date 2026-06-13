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

    public function testLogLineSnapshotKeepsLegacyFieldOrder(): void
    {
        $stats = array_fill_keys(array_keys(\pmssSystemStatsCollect()), 'value');
        $this->assertSame(
            '20250613 09:10:11 | load:value | cpu_iowait:value | mem_total:value | mem_free:value'
            .' | mem_cache:value | mem_buffers:value | swap_total:value | swap_free:value | disk_busy:value'
            .' | ioping_root:value | ioping_home:value | top_mem:value | psi_io:value | psi_mem:value',
            \pmssSystemStatsLogLine($stats, '20250613 09:10:11')
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

    public function testLoadAverageParserKeepsValidKernelFields(): void
    {
        $this->assertEquals(
            '0.00,0.11,1.25',
            \pmssSystemStatsLoadAverageFromRaw("0.00 0.11 1.25 3/212 12345\n")
        );
    }

    public function testLoadAverageParserRejectsMalformedBoundaryInput(): void
    {
        $malformedRows = [
            null,
            '',
            '0.01 0.02',
            '0.01 stack 0.03',
            '0.01 0.02 -0.03',
            '0.01 0.02 12345678901234567',
        ];

        foreach ($malformedRows as $raw) {
            $this->assertEquals('na,na,na', \pmssSystemStatsLoadAverageFromRaw($raw));
        }
    }

    public function testPsiParserSnapshotKeepsAppendOnlyFieldOrder(): void
    {
        $raw = "some avg10=1.23 avg60=4.56 avg300=7.89 total=123\nfull avg10=0.12 avg60=0.34 avg300=0.56 total=456\n";
        $this->assertSame('1.2/4.6/0.1/7.9/0.3/0.6/123/456', \pmssSystemStatsPsiFromRaw($raw));
        foreach ([null, "full avg10=1.0 total=1\n"] as $invalid) {
            $this->assertSame('na', \pmssSystemStatsPsiFromRaw($invalid));
        }
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

    public function testTopMemoryProcessesSummarizeRunnerStatus(): void
    {
        foreach ([1 => 'na', 0 => 'php-fpm:2.0M'] as $rc => $expected) {
            $summary = \pmssSystemStatsTopMemoryProcesses(static function (array &$output, int &$commandRc) use ($rc): void {
                $output = ['php-fpm 2048'];
                $commandRc = $rc;
            });

            $this->assertEquals($expected, $summary);
        }
    }
}
