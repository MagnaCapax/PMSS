<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/systemdSliceProperties.php';

class SystemdSlicePropertiesCharacterizationTest extends TestCase
{
    public function testParseOutputKeepsRequestedKeysOnly(): void
    {
        $parsed = \pmssParseSystemdPropertyOutput(
            ['MemoryHigh', 'MemoryMax', 'CPUQuota'],
            "MemoryHigh=524288000\nCPUQuota=250%\nIgnored=value\n"
        );

        $this->assertEquals(
            [
                'MemoryHigh' => '524288000',
                'MemoryMax' => '',
                'CPUQuota' => '250%',
            ],
            $parsed
        );
    }

    public function testBuildSystemdShowCommandEscapesUnitAndProperties(): void
    {
        $command = \pmssBuildSystemdShowCommand(
            "user-1000.slice; touch /tmp/pmss-test",
            ['CPUQuota', "MemoryMax; echo injected"]
        );

        $this->assertEquals(
            "systemctl show 'user-1000.slice; touch /tmp/pmss-test' -p 'CPUQuota' -p 'MemoryMax; echo injected' 2>/dev/null",
            $command
        );
    }

    public function testBuildSystemdShowCommandReturnsEmptyPrintfForEmptyProperties(): void
    {
        $this->assertEquals('printf %s ""', \pmssBuildSystemdShowCommand('user-1000.slice', []));
    }

    public function testBuildSystemdShowCommandRejectsNulAtEitherArgumentBoundary(): void
    {
        foreach (["\0value", "val\0ue", "value\0", "\0", "\0\0"] as $invalid) {
            $this->assertEquals('printf %s ""', \pmssBuildSystemdShowCommand($invalid, ['MemoryCurrent']));
            foreach ([[$invalid], ['MemoryCurrent', $invalid], [$invalid, 'MemoryCurrent']] as $properties) {
                $this->assertEquals('printf %s ""', \pmssBuildSystemdShowCommand('user-1000.slice', $properties));
            }
        }
    }

    public function testBuildSystemdShowCommandPreservesValidAndSkippedArguments(): void
    {
        $this->assertEquals(
            "systemctl show 'user-1000.slice' -p 'MemoryCurrent' -p 'TasksCurrent' 2>/dev/null",
            \pmssBuildSystemdShowCommand('user-1000.slice', ['', null, false, 42, [], 'MemoryCurrent', 'TasksCurrent'])
        );
        $this->assertEquals('printf %s ""', \pmssBuildSystemdShowCommand('user-1000.slice', ['', null, false, 42, []]));
        $this->assertEquals(
            "systemctl show '' -p 'MemoryCurrent' 2>/dev/null",
            \pmssBuildSystemdShowCommand('', ['MemoryCurrent'])
        );
    }

    public function testReadSystemdPropertiesKeepsEmptyResultShapeForNulArguments(): void
    {
        // These calls execute only the empty printf fallback, never systemctl.
        $this->assertEquals(
            ['MemoryCurrent' => '', 'TasksCurrent' => ''],
            \pmssReadSystemdProperties("user-1000.slice\0", ['MemoryCurrent', 'TasksCurrent'])
        );
        $this->assertEquals(
            ['MemoryCurrent' => '', "Tasks\0Current" => ''],
            \pmssReadSystemdProperties('user-1000.slice', ['MemoryCurrent', "Tasks\0Current"])
        );
    }

    public function testTrailingIntParsesPlainNumericValues(): void
    {
        $this->assertEquals(4096, \pmssSystemdPropertyTrailingInt('4096'));
    }

    public function testTrailingIntParsesDeviceQualifiedValues(): void
    {
        $this->assertEquals(1048576, \pmssSystemdPropertyTrailingInt('8:0 1048576'));
        $this->assertEquals(2048, \pmssSystemdPropertyTrailingInt('/dev/md0 2048'));
    }

    public function testTrailingIntRejectsUnsetInfinityAndZero(): void
    {
        $this->assertEquals(null, \pmssSystemdPropertyTrailingInt(''));
        $this->assertEquals(null, \pmssSystemdPropertyTrailingInt('infinity'));
        $this->assertEquals(null, \pmssSystemdPropertyTrailingInt('[not set]'));
        $this->assertEquals(null, \pmssSystemdPropertyTrailingInt('0'));
    }

    public function testCpuQuotaPercentPrefersDirectPercent(): void
    {
        $this->assertEquals(250, \pmssSystemdCpuQuotaPercent(['CPUQuota' => '250%']));
    }

    public function testCpuQuotaPercentParsesShowTimeSpans(): void
    {
        foreach (['2s' => 200, '13.600000s' => 1360, '500ms' => 50, '1min' => 6000,
            '1min 30s' => 9000, '1h 2min 3s 4ms 5us' => 372300, '500000' => 50] as $span => $expected) {
            $this->assertSame($expected, \pmssSystemdCpuQuotaPercent([
                'CPUQuotaPerSecUSec' => $span,
                'CPUQuotaPeriodUSec' => 'infinity',
            ]));
        }
    }

    public function testCpuQuotaPercentRejectsUnsetAndInvalidShowValues(): void
    {
        foreach (['infinity', '[not set]', '', '0', '0s', '2bogus', '1s garbage'] as $span) {
            $this->assertSame(null, \pmssSystemdCpuQuotaPercent([
                'CPUQuotaPerSecUSec' => $span,
                'CPUQuotaPeriodUSec' => 'infinity',
            ]));
        }
    }

    public function testCpuQuotaPercentTreatsInfinityAsMissing(): void
    {
        $quota = \pmssSystemdCpuQuotaPercent([
            'CPUQuota' => 'infinity',
            'CPUQuotaPerSecUSec' => '',
            'CPUQuotaPeriodUSec' => '',
        ]);

        $this->assertEquals(null, $quota);
    }

    public function testTimeSpanKeepsFiniteValuesAndWhitespaceNormalization(): void
    {
        foreach ([['0', 0.0], ['00012', 12.0], [' 12 ', 12.0], ['1.5ms', 1500.0],
            ['1min 30s', 90000000.0], ['1h 2min 3s 4ms 5us', 3723004005.0]] as $case) {
            $this->assertSame($case[1], \pmssSystemdTimeSpanUsec($case[0]));
        }
    }

    public function testTimeSpanRejectsOverflowDuringConversionMultiplicationAndAddition(): void
    {
        $huge = str_repeat('9', 400);
        $finite = str_repeat('9', 308);
        foreach ([$huge, '000'.$huge, $huge.'us', $huge.'ms', $finite.'h',
            $finite.'us '.$finite.'us', 'infinity', '1e309'] as $span) {
            $this->assertSame(null, \pmssSystemdTimeSpanUsec($span));
        }
    }

    public function testCpuQuotaPercentRejectsNonFiniteAndUnrepresentableValues(): void
    {
        // The next integer is representable as a float on both 32-bit and 64-bit PHP.
        $boundary = sprintf('%.0f', (float) PHP_INT_MAX + 1.0);
        foreach ([str_repeat('9', 400), '1e309', '-1e309', '1e100', $boundary, $boundary.'0'] as $raw) {
            $this->assertSame(null, \pmssSystemdCpuQuotaPercent([
                'CPUQuota' => $raw.'%', 'CPUQuotaPerSecUSec' => '1000000',
            ]));
        }
        foreach ([str_repeat('9', 400), str_repeat('9', 308), $boundary.'0000',
            $boundary.'0000us', $boundary.'s'] as $span) {
            $this->assertSame(null, \pmssSystemdCpuQuotaPercent(['CPUQuotaPerSecUSec' => $span]));
        }
    }

    public function testCpuQuotaPercentKeepsRoundingAndDirectValuePrecedence(): void
    {
        foreach (['250.4%' => 250, '250.5%' => 251, '0.49%' => null, '0.5%' => 1,
            ' 0250% ' => 250, '2.5e2%' => 250, 'garbage%' => null] as $raw => $expected) {
            $this->assertSame($expected, \pmssSystemdCpuQuotaPercent([
                'CPUQuota' => $raw, 'CPUQuotaPerSecUSec' => '1000000',
            ]));
        }
        foreach (['4999' => null, '5000' => 1, '14999' => 1, '15000' => 2, '2500000' => 250] as $span => $expected) {
            $this->assertSame($expected, \pmssSystemdCpuQuotaPercent(['CPUQuotaPerSecUSec' => (string) $span]));
        }
    }

    public function testCpuQuotaPercentKeepsLargeRepresentableIntegers(): void
    {
        $nearLimit = PHP_INT_SIZE >= 8 ? '9223372036854774784' : '2147483646';
        $this->assertSame((int) $nearLimit, \pmssSystemdCpuQuotaPercent(['CPUQuota' => $nearLimit.'%']));
    }

    public function testMapSystemdIntPropertiesAppliesDefaultsForOptionalCounters(): void
    {
        $mapped = \pmssMapSystemdIntProperties(
            [
                'IOReadBytes' => '11',
                'IOWriteBytes' => '22',
                'CPUUsageNSec' => '33',
                'MemoryCurrent' => '44',
                'TasksCurrent' => '55',
            ],
            [
                'IOReadBytes' => 'io_read',
                'IOWriteBytes' => 'io_write',
                'IOReadOperations' => 'io_read_ops',
                'IOWriteOperations' => 'io_write_ops',
                'CPUUsageNSec' => 'cpu_nsec',
                'MemoryCurrent' => 'memory',
                'TasksCurrent' => 'tasks',
            ],
            [
                'IOReadOperations' => 0,
                'IOWriteOperations' => 0,
            ]
        );

        $this->assertEquals(
            [
                'io_read' => 11,
                'io_write' => 22,
                'io_read_ops' => 0,
                'io_write_ops' => 0,
                'cpu_nsec' => 33,
                'memory' => 44,
                'tasks' => 55,
            ],
            $mapped
        );
    }

    public function testMapSystemdIntPropertiesRejectsMissingRequiredValues(): void
    {
        $mapped = \pmssMapSystemdIntProperties(
            [
                'IPIngressBytes' => '10',
            ],
            [
                'IPIngressBytes' => 'ingress',
                'IPEgressBytes' => 'egress',
            ]
        );

        $this->assertEquals(null, $mapped);
    }

    public function testMapSystemdIntPropertiesRejectsOversizedCounters(): void
    {
        $map = ['IPIngressBytes' => 'ingress'];
        foreach (['0' => 0, '00012' => 12, (string) PHP_INT_MAX => PHP_INT_MAX] as $raw => $expected) {
            $this->assertSame(['ingress' => $expected], \pmssMapSystemdIntProperties(['IPIngressBytes' => (string) $raw], $map));
        }
        foreach ([PHP_INT_MAX.'0', '000'.PHP_INT_MAX.'0'] as $raw) {
            $this->assertSame(null, \pmssMapSystemdIntProperties(['IPIngressBytes' => $raw], $map));
            $this->assertSame(null, \pmssMapSystemdIntProperties(['IPIngressBytes' => $raw], $map, ['IPIngressBytes' => 0]));
        }
    }
}
