<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/storageHealth.php';

class StorageHealthSmartctlParsingTest extends TestCase
{
    public function testSmartctlMetricsAndFindings(): void
    {
        // Keep ATA/SCSI parsing cases beside their complete ordered findings.
        $cases = [
            [[
                'SMART overall-health self-assessment test result: PASSED',
                '  5 Reallocated_Sector_Ct   0x0033   100   100   010    Pre-fail  Always       -       0',
                '197 Current_Pending_Sector  0x0012   100   100   000    Old_age   Always       -       0',
                '199 UDMA_CRC_Error_Count    0x003e   200   200   000    Old_age   Always       -       1',
                '194 Temperature_Celsius     0x0022   034   040   000    Old_age   Always       -       34',
                '  9 Power_On_Hours          0x0032   099   099   000    Old_age   Always       -       12345',
            ], [], null, 'ok', [], ['health' => 'PASSED', 'reallocated' => 0, 'pending' => 0, 'udma_crc' => 1, 'temp_c' => 34, 'power_on_hours' => 12345]],
            [[
                'SMART Health Status: OK',
                'Current Drive Temperature: 35 C',
                'Elements in grown defect list: 0',
                'Non-medium error count: 12',
            ], [], null, 'ok', [], ['health' => 'OK', 'reallocated' => 0, 'temp_c' => 35, 'link_errors' => 12]],
            [[
                'SMART overall-health self-assessment test result: PASSED',
                '199 UDMA_CRC_Error_Count    0x003e   200   200   000    Old_age   Always       -       7',
            ], [], null, 'ok', [], ['udma_crc' => 7, 'link_errors' => 7]],
            ["Some output without explicit health lines\n", [], null, 'warn', ['health_unknown'], []],
            ["SMART Health Status: FAILED\n", [], null, 'fail', ['health_not_ok'], []],
            ["SMART Health Status: OK FAIL\n", [], null, 'fail', ['health_not_ok'], []],
            ["Device is in STANDBY mode\n", [], null, 'ok', ['standby'], ['health' => 'STANDBY']],
            [[
                'SMART Health Status: FAILED',
                '197 Current_Pending_Sector  0x0012   100   100   000    Old_age   Always       -       1',
                '194 Temperature_Celsius     0x0022   034   040   000    Old_age   Always       -       70',
            ], [], null, 'fail', ['health_not_ok', 'pending_sectors', 'hot_hdd'], []],
            [[
                'SMART overall-health self-assessment test result: PASSED',
                '194 Temperature_Celsius     0x0022   034   040   000    Old_age   Always       -       70',
            ], ['model' => 'SSD', 'rota' => 0, 'size' => '1T'], null, 'warn', ['hot_ssd'], []],
            [[
                'SMART overall-health self-assessment test result: PASSED',
                '  5 Reallocated_Sector_Ct   0x0033   100   100   010    Pre-fail  Always       -       2',
                '197 Current_Pending_Sector  0x0012   100   100   000    Old_age   Always       -       1',
                '199 UDMA_CRC_Error_Count    0x003e   200   200   000    Old_age   Always       -       4',
            ], [], ['reallocated' => 1, 'pending' => 0, 'link_errors' => 2], 'warn', ['pending_sectors', 'reallocated_sectors', 'reallocated_increase', 'pending_increase', 'link_errors_increase'], []],
        ];
        foreach ($cases as [$output, $disk, $previous, $severity, $flags, $metrics]) {
            $entry = \pmssStorageHealthParseSmartctlOutput(
                is_array($output) ? implode("\n", $output)."\n" : $output,
                $disk + ['path' => '/dev/sda', 'kname' => 'sda', 'model' => 'TEST', 'serial' => 'X', 'rota' => 1, 'size' => '9T'],
                $previous,
                '2025-01-01T00:00:00+00:00'
            );
            $this->pmssAssertArraySubsetSame(['severity' => $severity, 'ok' => $severity === 'ok', 'flags' => $flags], $entry);
            $this->pmssAssertArraySubsetSame($metrics, $entry['metrics']);
        }
    }
}
