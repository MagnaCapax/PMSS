<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/runtime.php';
require_once dirname(__DIR__, 2).'/systemStats/hostPressure.php';

/** Pure input-boundary coverage for scripts/lib/systemStats/hostPressure.php. */
class SystemStatsHostPressureInputSafetyTest extends TestCase
{
    public function testMalformedContainersCannotWarnThrowOrSupplyMetrics(): void
    {
        $stream = fopen('php://memory', 'r+');
        $closedStream = fopen('php://memory', 'r+');
        fclose($closedStream);
        // Conversion hooks must never run, even if they offer plausible metric text.
        $stringable = new class {
            public function __toString(): string { return '1/2/3/4/5/6.5/7/8'; }
        };
        $throwing = new class {
            public function __toString(): string { throw new \RuntimeException('Unexpected metric conversion'); }
        };
        $incomplete = unserialize('O:8:"stdClass":0:{}', ['allowed_classes' => false]);

        set_error_handler(static function (int $severity, string $message, string $file, int $line): void {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            foreach ([[], ['1/2/3/4/5/6.5/7/8'], new \stdClass(), $stringable, $throwing,
                $incomplete, $stream, $closedStream] as $value) {
                // One unavailable source must not discard the valid sibling or reorder fields.
                $this->assertSame([
                    'timestamp' => 42, 'psi_io_full_avg300' => null, 'ioping_home_ms' => 123.4,
                ], \pmssSystemStatsHostPressurePayloadBuild(['psiIo' => $value, 'iopingHome' => '123.4ms'], 42));
                $this->assertSame([
                    'timestamp' => 42, 'psi_io_full_avg300' => 6.5, 'ioping_home_ms' => null,
                ], \pmssSystemStatsHostPressurePayloadBuild(['psiIo' => '1/2/3/4/5/6.5/7/8', 'iopingHome' => $value], 42));
            }
        } finally {
            restore_error_handler();
            fclose($stream);
        }
    }

    public function testScalarInputsKeepExistingConversionsAndMissingValueFallbacks(): void
    {
        foreach ([
            [null, null], ['', null], [false, null], [true, null], [0, null], [6.5, null],
            ['na', null], ['1/2/3', null], ['1/2/3/4/5/0/7/8', 0.0],
            ['1/2/3/4/5/6.5/7/8', 6.5], ["1/2/3/4/5/ \t6.5 \r\n/7/8", 6.5],
            ['1/2/3/4/5/1e2/7/8', 100.0], ['1/2/3/4/5/-1/7/8', null],
        ] as [$value, $expected]) {
            $this->assertSame([
                'timestamp' => 42, 'psi_io_full_avg300' => $expected, 'ioping_home_ms' => 0.0,
            ], \pmssSystemStatsHostPressurePayloadBuild(['psiIo' => $value, 'iopingHome' => '0ms'], 42));
        }
        // Missing sources retain the same complete payload with nullable metrics.
        $this->assertSame([
            'timestamp' => 42, 'psi_io_full_avg300' => null, 'ioping_home_ms' => null,
        ], \pmssSystemStatsHostPressurePayloadBuild([], 42));
    }
}
