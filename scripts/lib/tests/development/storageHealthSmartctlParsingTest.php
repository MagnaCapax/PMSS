<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/storageHealth.php';

class StorageHealthSmartctlParsingTest extends TestCase
{
    private function smartctlEntry(string $out, string $device = 'sda', array $disk = [], ?array $previous = null): array
    {
        return \pmssStorageHealthParseSmartctlOutput(
            $out,
            $disk + ['path' => '/dev/'.$device, 'kname' => $device, 'model' => 'TEST', 'serial' => 'X', 'rota' => 1, 'size' => '9T'],
            $previous,
            '2025-01-01T00:00:00+00:00'
        );
    }

    private function smartctlOutput(array $lines): string
    {
        return implode("\n", $lines)."\n";
    }

    private function assertFlags(array $entry, array $flags): void
    {
        foreach ($flags as $flag) {
            $this->assertTrue(in_array($flag, $entry['flags'], true), 'Missing storage-health flag: '.$flag);
        }
    }

    private function assertSeverityFlags(array $entry, string $severity, array $flags): void
    {
        $this->assertEquals($severity, $entry['severity']);
        $this->assertFlags($entry, $flags);
    }

    public function testAtaSmartPassedParsesMetrics(): void
    {
        $out = $this->smartctlOutput([
            'SMART overall-health self-assessment test result: PASSED',
            '  5 Reallocated_Sector_Ct   0x0033   100   100   010    Pre-fail  Always       -       0',
            '197 Current_Pending_Sector  0x0012   100   100   000    Old_age   Always       -       0',
            '199 UDMA_CRC_Error_Count    0x003e   200   200   000    Old_age   Always       -       1',
            '194 Temperature_Celsius     0x0022   034   040   000    Old_age   Always       -       34',
            '  9 Power_On_Hours          0x0032   099   099   000    Old_age   Always       -       12345',
        ]);

        $entry = $this->smartctlEntry($out);

        $this->pmssAssertArraySubsetSame(['severity' => 'ok', 'ok' => true], $entry);
        $this->pmssAssertArraySubsetSame(['health' => 'PASSED', 'reallocated' => 0, 'pending' => 0, 'udma_crc' => 1, 'temp_c' => 34, 'power_on_hours' => 12345], $entry['metrics']);
    }

    public function testScsiSmartOkUsesHealthStatusAndGrownDefects(): void
    {
        $out = $this->smartctlOutput([
            'SMART Health Status: OK',
            'Current Drive Temperature: 35 C',
            'Elements in grown defect list: 0',
            'Non-medium error count: 12',
        ]);

        $entry = $this->smartctlEntry($out, 'sdb');

        $this->pmssAssertArraySubsetSame(['severity' => 'ok', 'ok' => true], $entry);
        $this->pmssAssertArraySubsetSame(['health' => 'OK', 'reallocated' => 0, 'temp_c' => 35, 'link_errors' => 12], $entry['metrics']);
    }

    public function testUdmaCrcAlsoSetsLinkErrorsMetric(): void
    {
        $out = $this->smartctlOutput([
            'SMART overall-health self-assessment test result: PASSED',
            '199 UDMA_CRC_Error_Count    0x003e   200   200   000    Old_age   Always       -       7',
        ]);

        $entry = $this->smartctlEntry($out, 'sdz');

        $this->pmssAssertArraySubsetSame(['udma_crc' => 7, 'link_errors' => 7], $entry['metrics']);
    }

    public function testUnknownHealthDoesNotFailByDefault(): void
    {
        $out = "Some output without explicit health lines\n";
        $entry = $this->smartctlEntry($out, 'sdc');

        $this->assertSeverityFlags($entry, 'warn', ['health_unknown']);
    }

    public function testFailedHealthVariantsBecomeFailSeverity(): void
    {
        foreach ([
            ["SMART Health Status: FAILED\n", 'sdd'],
            ["SMART Health Status: OK FAIL\n", 'sdy'],
        ] as [$out, $device]) {
            $entry = $this->smartctlEntry($out, $device);
            $this->assertSeverityFlags($entry, 'fail', ['health_not_ok']);
        }
    }

    public function testStandbyIsOk(): void
    {
        $out = "Device is in STANDBY mode\n";
        $entry = $this->smartctlEntry($out, 'sde');

        $this->pmssAssertArraySubsetSame(['severity' => 'ok', 'ok' => true], $entry);
        $this->assertFlags($entry, ['standby']);
        $this->assertEquals('STANDBY', $entry['metrics']['health']);
    }

    public function testFailedHealthStaysFailWhenWarnFlagsAlsoApply(): void
    {
        $out = $this->smartctlOutput([
            'SMART Health Status: FAILED',
            '197 Current_Pending_Sector  0x0012   100   100   000    Old_age   Always       -       1',
            '194 Temperature_Celsius     0x0022   034   040   000    Old_age   Always       -       70',
        ]);
        $entry = $this->smartctlEntry($out, 'sdx');

        $this->assertSeverityFlags($entry, 'fail', ['health_not_ok', 'pending_sectors']);
    }

    public function testSsdTemperatureThresholdUsesHotSsdFlag(): void
    {
        $out = $this->smartctlOutput([
            'SMART overall-health self-assessment test result: PASSED',
            '194 Temperature_Celsius     0x0022   034   040   000    Old_age   Always       -       70',
        ]);

        $entry = $this->smartctlEntry($out, 'nvme0n1', ['model' => 'SSD', 'rota' => 0, 'size' => '1T']);

        $this->assertSeverityFlags($entry, 'warn', ['hot_ssd']);
    }

    public function testPreviousMetricIncreasesSetExpectedFlags(): void
    {
        $out = $this->smartctlOutput([
            'SMART overall-health self-assessment test result: PASSED',
            '  5 Reallocated_Sector_Ct   0x0033   100   100   010    Pre-fail  Always       -       2',
            '197 Current_Pending_Sector  0x0012   100   100   000    Old_age   Always       -       1',
            '199 UDMA_CRC_Error_Count    0x003e   200   200   000    Old_age   Always       -       4',
        ]);

        $entry = $this->smartctlEntry($out, 'sdf', [], ['reallocated' => 1, 'pending' => 0, 'link_errors' => 2]);

        $this->assertSeverityFlags($entry, 'warn', ['reallocated_increase', 'pending_increase', 'link_errors_increase']);
    }
}
