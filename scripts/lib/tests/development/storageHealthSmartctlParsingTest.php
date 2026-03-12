<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/storageHealth.php';

class StorageHealthSmartctlParsingTest extends TestCase
{
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

        $disk = ['path' => '/dev/sda', 'kname' => 'sda', 'model' => 'TEST', 'serial' => 'X', 'rota' => 1, 'size' => '9T'];
        $entry = \pmssStorageHealthParseSmartctlOutput($out, $disk, null, '2025-01-01T00:00:00+00:00');

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

        $disk = ['path' => '/dev/sdb', 'kname' => 'sdb', 'model' => 'TEST', 'serial' => 'Y', 'rota' => 1, 'size' => '9T'];
        $entry = \pmssStorageHealthParseSmartctlOutput($out, $disk, null, '2025-01-01T00:00:00+00:00');

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

        $disk = ['path' => '/dev/sdz', 'kname' => 'sdz', 'model' => 'TEST', 'serial' => 'U', 'rota' => 1, 'size' => '9T'];
        $entry = \pmssStorageHealthParseSmartctlOutput($out, $disk, null, '2025-01-01T00:00:00+00:00');

        $this->assertEquals(7, $entry['metrics']['udma_crc']);
        $this->assertEquals(7, $entry['metrics']['link_errors']);
    }

    public function testUnknownHealthDoesNotFailByDefault(): void
    {
        $out = "Some output without explicit health lines\n";
        $disk = ['path' => '/dev/sdc', 'kname' => 'sdc', 'model' => 'TEST', 'serial' => 'Z', 'rota' => 1, 'size' => '9T'];
        $entry = \pmssStorageHealthParseSmartctlOutput($out, $disk, null, '2025-01-01T00:00:00+00:00');

        $this->assertEquals('warn', $entry['severity']);
        $this->assertTrue(in_array('health_unknown', $entry['flags'], true));
    }

    public function testFailedHealthBecomesFailSeverity(): void
    {
        $out = "SMART Health Status: FAILED\n";
        $disk = ['path' => '/dev/sdd', 'kname' => 'sdd', 'model' => 'TEST', 'serial' => 'W', 'rota' => 1, 'size' => '9T'];
        $entry = \pmssStorageHealthParseSmartctlOutput($out, $disk, null, '2025-01-01T00:00:00+00:00');

        $this->assertEquals('fail', $entry['severity']);
        $this->assertTrue(in_array('health_not_ok', $entry['flags'], true));
    }

    public function testFailKeywordOverridesOkKeyword(): void
    {
        $out = "SMART Health Status: OK FAIL\n";
        $disk = ['path' => '/dev/sdy', 'kname' => 'sdy', 'model' => 'TEST', 'serial' => 'Q', 'rota' => 1, 'size' => '9T'];
        $entry = \pmssStorageHealthParseSmartctlOutput($out, $disk, null, '2025-01-01T00:00:00+00:00');

        $this->assertEquals('fail', $entry['severity']);
        $this->assertTrue(in_array('health_not_ok', $entry['flags'], true));
    }

    public function testStandbyIsOk(): void
    {
        $out = "Device is in STANDBY mode\n";
        $disk = ['path' => '/dev/sde', 'kname' => 'sde', 'model' => 'TEST', 'serial' => 'V', 'rota' => 1, 'size' => '9T'];
        $entry = \pmssStorageHealthParseSmartctlOutput($out, $disk, null, '2025-01-01T00:00:00+00:00');

        $this->assertEquals('ok', $entry['severity']);
        $this->assertTrue($entry['ok']);
        $this->assertTrue(in_array('standby', $entry['flags'], true));
        $this->assertEquals('STANDBY', $entry['metrics']['health']);
    }

    public function testSeverityMaxUsesRankOrder(): void
    {
        $this->assertEquals('warn', \pmssStorageHealthSeverityMax('ok', 'warn'));
        $this->assertEquals('fail', \pmssStorageHealthSeverityMax('warn', 'fail'));
        $this->assertEquals('fail', \pmssStorageHealthSeverityMax('fail', 'ok'));
    }

    public function testSsdTemperatureThresholdUsesHotSsdFlag(): void
    {
        $out = implode("\n", [
            'SMART overall-health self-assessment test result: PASSED',
            '194 Temperature_Celsius     0x0022   034   040   000    Old_age   Always       -       70',
        ])."\n";
        $disk = ['path' => '/dev/nvme0n1', 'kname' => 'nvme0n1', 'model' => 'SSD', 'serial' => 'S', 'rota' => 0, 'size' => '1T'];

        $entry = \pmssStorageHealthParseSmartctlOutput($out, $disk, null, '2025-01-01T00:00:00+00:00');

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
        $disk = ['path' => '/dev/sdf', 'kname' => 'sdf', 'model' => 'TEST', 'serial' => 'I', 'rota' => 1, 'size' => '9T'];
        $prev = [
            'reallocated' => 1,
            'pending' => 0,
            'link_errors' => 2,
        ];

        $entry = \pmssStorageHealthParseSmartctlOutput($out, $disk, $prev, '2025-01-01T00:00:00+00:00');

        $this->assertTrue(in_array('reallocated_increase', $entry['flags'], true));
        $this->assertTrue(in_array('pending_increase', $entry['flags'], true));
        $this->assertTrue(in_array('link_errors_increase', $entry['flags'], true));
        $this->assertEquals('warn', $entry['severity']);
    }
}
