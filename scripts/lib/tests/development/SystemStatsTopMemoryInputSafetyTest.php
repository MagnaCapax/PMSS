<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/systemStats/observe.php';

/** Input-boundary coverage for scripts/lib/systemStats/parse.php without host probes. */
class SystemStatsTopMemoryInputSafetyTest extends TestCase
{
    public function testMalformedRowsCannotWarnThrowOrSupplyMetrics(): void
    {
        $stream = fopen('php://memory', 'r+');
        $closedStream = fopen('php://memory', 'r+');
        fclose($closedStream);
        // Even plausible rows from conversion hooks are outside the probe contract.
        $stringable = new class {
            public function __toString(): string { return 'fixture03 123'; }
        };
        $throwing = new class {
            public function __toString(): string { throw new \RuntimeException('Unexpected row conversion'); }
        };
        $incomplete = unserialize('O:8:"stdClass":0:{}', ['allowed_classes' => false]);

        set_error_handler(static function (int $severity, string $message, string $file, int $line): void {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            foreach ([null, [], ['fixture03 123'], new \stdClass(), $stringable, $throwing,
                $incomplete, $stream, $closedStream] as $value) {
                $this->assertSame('na', \pmssSystemStatsTopMemoryFromPsRows([$value]));
                // Invalid entries cannot discard, reorder or replace valid siblings.
                $rows = ['fixture01 0', $value, 'fixture02 1024'];
                $this->assertSame('fixture01:0K,fixture02:1.0M', \pmssSystemStatsTopMemoryFromPsRows($rows));
                $this->assertSame('fixture01:0K,fixture02:1.0M', \pmssSystemStatsTopMemoryProcesses(
                    static function (array &$output, int &$rc) use ($rows): void {
                        $output = $rows;
                        $rc = 0;
                    }
                ));
            }
        } finally {
            restore_error_handler();
            fclose($stream);
        }
    }

    public function testScalarRowsKeepFormattingAndUnavailableFallbacks(): void
    {
        foreach ([
            ['', 'na'], [false, 'na'], [true, 'na'], [0, 'na'], [123, 'na'], [1.5, 'na'],
            [NAN, 'na'], [INF, 'na'], ['fixture01 0', 'fixture01:0K'],
            [" \tfixture01\t000 \r\n", 'fixture01:0K'], ['000 000', '000:0K'],
            ['fixture01 1023', 'fixture01:1023K'], ['fixture01 1024', 'fixture01:1.0M'],
            ['fixture01 1048576', 'fixture01:1.0G'], ['fixture01 -1', 'na'],
            ['fixture01 1.5', 'na'], ['fixture01 1e2', 'na'], ['bad:name 123', 'na'],
            ["fixture01 123\0", 'na'],
        ] as [$row, $expected]) {
            $this->assertSame($expected, \pmssSystemStatsTopMemoryFromPsRows([$row]));
        }
        $this->assertSame('na', \pmssSystemStatsTopMemoryFromPsRows([]));
        $this->assertSame('fixture01:1K,fixture02:2K,fixture01:3K', \pmssSystemStatsTopMemoryFromPsRows([
            'fixture01 1', 'fixture02 2', 'fixture01 3',
        ]));
    }

    public function testFailedCaptureStillDiscardsValidPartialRows(): void
    {
        foreach ([1, 124, 255] as $status) {
            $this->assertSame('na', \pmssSystemStatsTopMemoryProcesses(
                static function (array &$output, int &$rc) use ($status): void {
                    $output = ['fixture01 1024', ['malformed']];
                    $rc = $status;
                }
            ));
        }
    }
}
