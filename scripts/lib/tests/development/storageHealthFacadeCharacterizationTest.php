<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/storageHealth.php';

final class StorageHealthFacadeCharacterizationTest extends TestCase
{
    public function testSmartAndRaidPayloadSnapshot(): void
    {
        // Freeze complete payloads, including key/flag order, temperature boundaries,
        // informational counter growth, failed health, and repeated RAID activity.
        $entries = [];
        foreach (['', 'OK', 'PASSED', 'FAILED', 'OK FAIL', 'BAD', 'STANDBY'] as $health) {
            foreach ([0, 49, 50, 69, 70] as $temperature) {
                foreach ([0, 1] as $rota) {
                    foreach ([null, -1, 0, '0', 2] as $previous) {
                        $out = $health === 'STANDBY' ? 'Device is in STANDBY' : ($health === '' ? '' : 'SMART Health Status: '.$health);
                        $out .= "\nCurrent Drive Temperature: {$temperature} C\nElements in grown defect list: 0\nNon-medium error count: 1\n";
                        $entries[] = \pmssStorageHealthParseSmartctlOutput($out, ['path' => '/dev/sda', 'rota' => $rota], ['reallocated' => $previous, 'pending' => $previous, 'link_errors' => $previous], '2025-01-01T00:00:00+00:00');
                    }
                }
            }
        }
        foreach (['[2/2] [UU]', '[2/1] [U_]', '[2/1] [UU]'] as $detail) {
            foreach (['', 'check', 'resync', 'recovery', 'reshape'] as $operation) {
                $activity = $operation === '' ? '' : "\n {$operation} = 10.0% finish=2min speed=100K/sec";
                $entries[] = \pmssStorageHealthRaidEntriesParse("md0 : active raid1 {$detail}".$activity.$activity, '2025-01-01T00:00:00+00:00');
            }
        }
        $this->assertSame('4399d9d1f6cef768172bdf798502eb63a330341cfa2724975846053a1badf348', hash('sha256', json_encode($entries)));
    }

    public function testDiskInventoryParserMatchesSharedLsblkShape(): void
    {
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

    public function testFacadeLoadsSnapshotBackendEntrypoints(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/lib/storageHealth.php', ["storageHealth/nvme.php", "storageHealth/raid.php"], ['function pmssStorageHealthSnapshotNvme(', 'function pmssStorageHealthSnapshotRaid(']);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/storageHealth/nvme.php', ['function pmssStorageHealthSnapshotNvme(']);
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/storageHealth/raid.php', ['function pmssStorageHealthSnapshotRaid(']);
    }
}
