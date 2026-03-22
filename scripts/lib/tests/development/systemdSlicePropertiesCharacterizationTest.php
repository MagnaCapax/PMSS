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

    public function testCpuQuotaPercentFallsBackToPeriodRatio(): void
    {
        $quota = \pmssSystemdCpuQuotaPercent([
            'CPUQuotaPerSecUSec' => '50000',
            'CPUQuotaPeriodUSec' => '100000',
        ]);

        $this->assertEquals(50, $quota);
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
}
