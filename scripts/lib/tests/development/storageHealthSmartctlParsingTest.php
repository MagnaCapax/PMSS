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

    public function testAtaSmartPassedParsesMetrics(): void
    {
        $out = implode("\n", [
            'SMART overall-health self-assessment test result: PASSED',
            '  5 Reallocated_Sector_Ct   0x0033   100   100   010    Pre-fail  Always       -       0',
            '197 Current_Pending_Sector  0x0012   100   100   000    Old_age   Always       -       0',
            '199 UDMA_CRC_Error_Count    0x003e   200   200   000    Old_age   Always       -       1',
            '194 Temperature_Celsius     0x0022   034   040   000    Old_age   Always       -       34',
            '  9 Power_On_Hours          0x0032   099   099   000    Old_age   Always       -       12345',
        ])."\n";

        $entry = $this->smartctlEntry($out);

        $this->assertEquals('ok', $entry['severity']);
        $this->assertTrue($entry['ok']);
        $this->assertEquals('PASSED', $entry['metrics']['health']);
        $this->assertEquals(0, $entry['metrics']['reallocated']);
        $this->assertEquals(0, $entry['metrics']['pending']);
        $this->assertEquals(1, $entry['metrics']['udma_crc']);
        $this->assertEquals(34, $entry['metrics']['temp_c']);
        $this->assertEquals(12345, $entry['metrics']['power_on_hours']);
    }

    public function testScsiSmartOkUsesHealthStatusAndGrownDefects(): void
    {
        $out = implode("\n", [
            'SMART Health Status: OK',
            'Current Drive Temperature: 35 C',
            'Elements in grown defect list: 0',
            'Non-medium error count: 12',
        ])."\n";

        $entry = $this->smartctlEntry($out, 'sdb');

        $this->assertEquals('ok', $entry['severity']);
        $this->assertTrue($entry['ok']);
        $this->assertEquals('OK', $entry['metrics']['health']);
        $this->assertEquals(0, $entry['metrics']['reallocated']);
        $this->assertEquals(35, $entry['metrics']['temp_c']);
        $this->assertEquals(12, $entry['metrics']['link_errors']);
    }

    public function testUdmaCrcAlsoSetsLinkErrorsMetric(): void
    {
        $out = implode("\n", [
            'SMART overall-health self-assessment test result: PASSED',
            '199 UDMA_CRC_Error_Count    0x003e   200   200   000    Old_age   Always       -       7',
        ])."\n";

        $entry = $this->smartctlEntry($out, 'sdz');

        $this->assertEquals(7, $entry['metrics']['udma_crc']);
        $this->assertEquals(7, $entry['metrics']['link_errors']);
    }

    public function testUnknownHealthDoesNotFailByDefault(): void
    {
        $out = "Some output without explicit health lines\n";
        $entry = $this->smartctlEntry($out, 'sdc');

        $this->assertEquals('warn', $entry['severity']);
        $this->assertTrue(in_array('health_unknown', $entry['flags'], true));
    }

    public function testFailedHealthVariantsBecomeFailSeverity(): void
    {
        foreach ([
            ["SMART Health Status: FAILED\n", 'sdd'],
            ["SMART Health Status: OK FAIL\n", 'sdy'],
        ] as [$out, $device]) {
            $entry = $this->smartctlEntry($out, $device);
            $this->assertEquals('fail', $entry['severity']);
            $this->assertTrue(in_array('health_not_ok', $entry['flags'], true));
        }
    }

    public function testStandbyIsOk(): void
    {
        $out = "Device is in STANDBY mode\n";
        $entry = $this->smartctlEntry($out, 'sde');

        $this->assertEquals('ok', $entry['severity']);
        $this->assertTrue($entry['ok']);
        $this->assertTrue(in_array('standby', $entry['flags'], true));
        $this->assertEquals('STANDBY', $entry['metrics']['health']);
    }

    public function testFailedHealthStaysFailWhenWarnFlagsAlsoApply(): void
    {
        $out = implode("\n", [
            'SMART Health Status: FAILED',
            '197 Current_Pending_Sector  0x0012   100   100   000    Old_age   Always       -       1',
            '194 Temperature_Celsius     0x0022   034   040   000    Old_age   Always       -       70',
        ])."\n";
        $entry = $this->smartctlEntry($out, 'sdx');

        $this->assertEquals('fail', $entry['severity']);
        $this->assertTrue(in_array('health_not_ok', $entry['flags'], true));
        $this->assertTrue(in_array('pending_sectors', $entry['flags'], true));
    }

    public function testSsdTemperatureThresholdUsesHotSsdFlag(): void
    {
        $out = implode("\n", [
            'SMART overall-health self-assessment test result: PASSED',
            '194 Temperature_Celsius     0x0022   034   040   000    Old_age   Always       -       70',
        ])."\n";

        $entry = $this->smartctlEntry($out, 'nvme0n1', ['model' => 'SSD', 'rota' => 0, 'size' => '1T']);

        $this->assertEquals('warn', $entry['severity']);
        $this->assertTrue(in_array('hot_ssd', $entry['flags'], true));
    }

    public function testPreviousMetricIncreasesSetExpectedFlags(): void
    {
        $out = implode("\n", [
            'SMART overall-health self-assessment test result: PASSED',
            '  5 Reallocated_Sector_Ct   0x0033   100   100   010    Pre-fail  Always       -       2',
            '197 Current_Pending_Sector  0x0012   100   100   000    Old_age   Always       -       1',
            '199 UDMA_CRC_Error_Count    0x003e   200   200   000    Old_age   Always       -       4',
        ])."\n";

        $entry = $this->smartctlEntry($out, 'sdf', [], ['reallocated' => 1, 'pending' => 0, 'link_errors' => 2]);

        $this->assertTrue(in_array('reallocated_increase', $entry['flags'], true));
        $this->assertTrue(in_array('pending_increase', $entry['flags'], true));
        $this->assertTrue(in_array('link_errors_increase', $entry['flags'], true));
        $this->assertEquals('warn', $entry['severity']);
    }
}
