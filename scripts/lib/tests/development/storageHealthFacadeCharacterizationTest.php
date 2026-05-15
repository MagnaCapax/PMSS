<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class StorageHealthFacadeCharacterizationTest extends TestCase
{
    public function testDiskInventoryParserMatchesSharedLsblkShape(): void
    {
        require_once dirname(__DIR__, 2).'/storageHealth/common.php';

        $lsblk = "sda disk 0 Samsung SSD SN123 1.8T\n"
            ."loop0 loop 0 Loop Dev L0 1G\n"
            ."ram0 disk 0 Ram Disk R0 64M\n"
            ."nvme0n1 disk 0 Samsung 980 PRO S6XYZ 3.5T\n";

        $this->assertSame(
            [
                ['path' => '/dev/sda', 'kname' => 'sda', 'rota' => 0, 'model' => 'Samsung SSD', 'serial' => 'SN123', 'size' => '1.8T'],
                ['path' => '/dev/nvme0n1', 'kname' => 'nvme0n1', 'rota' => 0, 'model' => 'Samsung 980 PRO', 'serial' => 'S6XYZ', 'size' => '3.5T'],
            ],
            \pmssStorageHealthDiskInventoryFromLsblk($lsblk)
        );
    }

    public function testFacadeOwnsNvmeAndRaidSnapshotEntrypoints(): void
    {
        $facadePath = dirname(__DIR__, 4).'/scripts/lib/storageHealth.php';
        $facadeSource = @file_get_contents($facadePath);

        $this->assertTrue(is_string($facadeSource) && $facadeSource !== '', 'Expected to read '.$facadePath);
        $this->assertStringContainsString('function pmssStorageHealthSnapshotNvme(', $facadeSource);
        $this->assertStringContainsString('function pmssStorageHealthSnapshotRaid(', $facadeSource);
        $this->assertTrue(!is_file(dirname($facadePath).'/storageHealth/nvme.php'));
        $this->assertTrue(!is_file(dirname($facadePath).'/storageHealth/raid.php'));
    }
}
