<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/storageHealth.php';

final class StorageHealthSnapshotSmartCharacterizationTest extends TestCase
{
    /** @var string|false */
    private $previousPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousPath = getenv('PATH');
    }

    protected function tearDown(): void
    {
        $this->pmssRestoreEnv('PATH', $this->previousPath);
        parent::tearDown();
    }

    public function testSnapshotSmartKeepsLegacyUdmaCrcHistoryCompatible(): void
    {
        $device = $this->pmssCreateReadableDevice();
        $this->pmssInstallSmartctlStub(implode("\n", [
            '#!/bin/sh',
            'cat <<\'EOF\'',
            'SMART overall-health self-assessment test result: PASSED',
            '199 UDMA_CRC_Error_Count    0x003e   200   200   000    Old_age   Always       -       4',
            'EOF',
        ])."\n");

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
    }

    private function pmssCreateReadableDevice(): string
    {
        $device = tempnam($this->pmssMakeTempDir('pmss-smart-device-'), 'dev-');
        $this->assertTrue($device !== false, 'Expected a temporary device placeholder');
        return (string) $device;
    }

    private function pmssInstallSmartctlStub(string $script): void
    {
        $binDir = $this->pmssMakeTempDir('pmss-smart-bin-');
        file_put_contents($binDir.'/smartctl', $script);
        @chmod($binDir.'/smartctl', 0755);

        $path = $binDir;
        if ($this->previousPath !== false && $this->previousPath !== '') {
            $path .= ':'.$this->previousPath;
        }
        putenv('PATH='.$path);
    }
}
