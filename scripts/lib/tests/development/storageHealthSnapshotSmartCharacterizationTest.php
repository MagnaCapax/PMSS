<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/storageHealth.php';

final class StorageHealthSnapshotSmartCharacterizationTest extends TestCase
{
    public function testSnapshotSmartKeepsLegacyUdmaCrcHistoryCompatible(): void
    {
        $device = $this->pmssMakeReadableTempPath('pmss-smart-device-', 'dev-');
        $stubDir = $this->pmssMakeExecutableStub('smartctl', implode("\n", [
            '#!/bin/sh',
            'cat <<\'EOF\'',
            'SMART overall-health self-assessment test result: PASSED',
            '199 UDMA_CRC_Error_Count    0x003e   200   200   000    Old_age   Always       -       4',
            'EOF',
        ])."\n", 'pmss-smart-bin-');

        $this->pmssWithPathPrefix($stubDir, function () use ($device): void {
            $entry = \pmssStorageHealthSnapshotSmart(
                ['path' => $device, 'kname' => 'sdz', 'model' => 'TEST', 'serial' => 'CRC', 'rota' => 1, 'size' => '1T'],
                ['smart::'.$device => ['metrics' => ['udma_crc' => 2]]],
                '2025-01-01T00:00:00+00:00'
            );

            $this->pmssAssertArraySubsetSame(['kind' => 'smart', 'device' => $device, 'flags' => ['link_errors_increase'], 'severity' => 'ok', 'ok' => true], $entry);
            $this->pmssAssertArraySubsetSame(['udma_crc' => 4, 'link_errors' => 4], $entry['metrics']);
        });
    }
}
