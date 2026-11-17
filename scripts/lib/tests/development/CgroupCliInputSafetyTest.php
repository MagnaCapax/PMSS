<?php
/** Pure numeric-option boundary coverage for scripts/lib/cgroup/cliInput.php. */
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/cgroup/cliInput.php';

final class CgroupCliInputSafetyTest extends TestCase
{
    public function testContainersUseExistingRefusalsWithoutWarningsOrConversionHooks(): void
    {
        $stream = fopen('php://memory', 'r+');
        $closedStream = fopen('php://memory', 'r+');
        fclose($closedStream);
        $stringable = new class {
            public $calls = 0;
            public function __toString(): string { $this->calls++; return '50'; }
        };
        $throwing = new class {
            public function __toString(): string { throw new \Error('Unexpected option conversion'); }
        };
        $incomplete = unserialize('O:8:"stdClass":0:{}', ['allowed_classes' => false]);

        try {
            $this->pmssAssertNoPhpWarnings(function () use ($stream, $closedStream, $stringable, $throwing, $incomplete): void {
                foreach (array_merge(\PMSS\Cgroup\PMSS_CGROUP_INTEGER_OPTIONS, ['cpu-quota-percent']) as $key) {
                    $expected = 'Invalid --'.$key.' value: expected integer'
                        .($key === 'cpu-quota-percent' ? ' or infinity' : '');
                    foreach ([[], ['50'], ['value' => '50'], new \stdClass(), $stringable,
                        $throwing, $incomplete, $stream, $closedStream] as $value) {
                        $this->assertSame($expected, \PMSS\Cgroup\pmssCgroupCliValidateFlagOptions([$key => $value], '', ''));
                    }
                }
            });
            $this->assertSame(0, $stringable->calls);
        } finally {
            fclose($stream);
        }
    }

    public function testScalarConversionsAndNullOptionsKeepTheirResults(): void
    {
        foreach (array_merge(\PMSS\Cgroup\PMSS_CGROUP_INTEGER_OPTIONS, ['cpu-quota-percent']) as $key) {
            $latency = $key === 'io-latency-ms';
            $integerError = 'Invalid --'.$key.' value: expected integer'
                .($key === 'cpu-quota-percent' ? ' or infinity' : '');
            $positiveError = $latency ? 'Invalid --io-latency-ms value: expected positive integer' : null;
            foreach ([
                [null, null], [true, null], [false, $integerError], [50, null], [50.0, null],
                ['050', null], ['0', $positiveError], [0, $positiveError], ['-1', $positiveError],
                ['', $integerError], ['5.5', $integerError], [5.5, $integerError],
                ["50\n", $integerError], ["50\0", $integerError], [' 50', $integerError],
                ['infinity', $key === 'cpu-quota-percent' ? null : $integerError],
                ['INFINITY', $key === 'cpu-quota-percent' ? null : $integerError],
            ] as [$value, $expected]) {
                $this->assertSame($expected, \PMSS\Cgroup\pmssCgroupCliValidateFlagOptions([$key => $value], '', ''));
            }
        }
        $this->assertSame(null, \PMSS\Cgroup\pmssCgroupCliValidateFlagOptions([], '', ''));
    }

    public function testRefusalOrderingAndUnrelatedOptionsStayUnchanged(): void
    {
        // Integer checks precede latency, IO cost checks and the separate quota check.
        $this->assertSame('Invalid --cpu-weight value: expected integer',
            \PMSS\Cgroup\pmssCgroupCliValidateFlagOptions(['cpu-weight' => [], 'io-latency-ms' => 0], "bad\n", ''));
        $this->assertSame('Invalid --io-latency-ms value: expected positive integer',
            \PMSS\Cgroup\pmssCgroupCliValidateFlagOptions(['io-latency-ms' => 0, 'cpu-quota-percent' => []], "bad\n", ''));
        foreach (['io-cost-qos', 'io-cost-model'] as $key) {
            $this->assertSame('Invalid --'.$key.' value: newline and NUL bytes are not allowed',
                \PMSS\Cgroup\pmssCgroupCliValidateFlagOptions(['cpu-quota-percent' => []],
                    $key === 'io-cost-qos' ? "bad\n" : '', $key === 'io-cost-model' ? "bad\0" : ''));
        }
        $this->assertSame(null, \PMSS\Cgroup\pmssCgroupCliValidateFlagOptions(['unknown' => []], '', ''));
    }
}
