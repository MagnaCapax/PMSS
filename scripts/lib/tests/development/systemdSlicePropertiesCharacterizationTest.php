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
}
