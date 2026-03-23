<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/storageHealth.php';

final class StorageHealthSnapshotSmartCharacterizationTest extends TestCase
{
    public function testSnapshotSmartKeepsLegacyUdmaCrcHistoryCompatible(): void
    {
        $device = $this->pmssCreateReadableDevice();
        $stubDir = $this->pmssCreateSmartctlStubDir(implode("\n", [
            '#!/bin/sh',
            'cat <<\'EOF\'',
            'SMART overall-health self-assessment test result: PASSED',
            '199 UDMA_CRC_Error_Count    0x003e   200   200   000    Old_age   Always       -       4',
            'EOF',
        ])."\n");

        $this->pmssWithPathPrefix($stubDir, function () use ($device): void {
            $entry = \pmssStorageHealthSnapshotSmart(
                ['path' => $device, 'kname' => 'sdz', 'model' => 'TEST', 'serial' => 'CRC', 'rota' => 1, 'size' => '1T'],
                ['smart::'.$device => ['metrics' => ['udma_crc' => 2]]],
                '2025-01-01T00:00:00+00:00'
            );

            $this->assertEquals('smart', $entry['kind']);
            $this->assertEquals($device, $entry['device']);
            $this->assertEquals(4, $entry['metrics']['udma_crc']);
            $this->assertEquals(4, $entry['metrics']['link_errors']);
            $this->assertTrue(in_array('link_errors_increase', $entry['flags'], true));
            $this->assertEquals('ok', $entry['severity']);
            $this->assertTrue($entry['ok']);
        });
    }

    private function pmssCreateReadableDevice(): string
    {
        return $this->pmssMakeReadableTempPath('pmss-smart-device-', 'dev-');
    }

    private function pmssCreateSmartctlStubDir(string $script): string
    {
        return $this->pmssMakeExecutableStub('smartctl', $script, 'pmss-smart-bin-');
    }
}
