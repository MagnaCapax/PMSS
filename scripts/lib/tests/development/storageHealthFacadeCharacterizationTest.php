<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class StorageHealthFacadeCharacterizationTest extends TestCase
{
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
