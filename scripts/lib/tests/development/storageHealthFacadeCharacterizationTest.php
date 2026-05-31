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

    public function testDiskInventoryParserRejectsUnsafeKernelNames(): void
    {
        require_once dirname(__DIR__, 2).'/storageHealth/common.php';

        $lsblk = "../sda disk 0 Bad Traversal BAD 1T\n"
            ."sdb/evil disk 0 Bad Slash BAD 1T\n"
            ."sdc\\evil disk 0 Bad Backslash BAD 1T\n"
            .". disk 0 Bad Dot BAD 1T\n"
            .'$(id) disk 0 Bad Shell BAD 1T'."\n"
            ."semi;colon disk 0 Bad Semicolon BAD 1T\n"
            ."pipe|name disk 0 Bad Pipe BAD 1T\n"
            .'back`tick disk 0 Bad Backtick BAD 1T'."\n"
            ."dm-0 disk 0 MapperVol DMSER 1T\n"
            ."cciss!c0d0 disk 1 SmartArray HPSER 1T\n";

        $this->assertSame(
            [
                ['path' => '/dev/dm-0', 'kname' => 'dm-0', 'rota' => 0, 'model' => 'MapperVol', 'serial' => 'DMSER', 'size' => '1T'],
                ['path' => '/dev/cciss!c0d0', 'kname' => 'cciss!c0d0', 'rota' => 1, 'model' => 'SmartArray', 'serial' => 'HPSER', 'size' => '1T'],
            ],
            \pmssStorageHealthDiskInventoryFromLsblk($lsblk)
        );
    }

    public function testFacadeOwnsNvmeAndRaidSnapshotEntrypoints(): void
    {
        $facadePath = $this->pmssRepoPath('scripts/lib/storageHealth.php');
        $facadeSource = @file_get_contents($facadePath);

        $this->assertTrue(is_string($facadeSource) && $facadeSource !== '', 'Expected to read '.$facadePath);
        $this->assertStringContainsString('function pmssStorageHealthSnapshotNvme(', $facadeSource);
        $this->assertStringContainsString('function pmssStorageHealthSnapshotRaid(', $facadeSource);
        $this->assertTrue(!is_file(dirname($facadePath).'/storageHealth/nvme.php'));
        $this->assertTrue(!is_file(dirname($facadePath).'/storageHealth/raid.php'));
    }
}
