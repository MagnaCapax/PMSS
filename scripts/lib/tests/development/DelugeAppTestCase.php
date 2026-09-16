<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

putenv('PMSS_DELUGE_NO_ENTRYPOINT=1');
require_once dirname(__DIR__, 2).'/update/apps/deluge.php';

/**
 * Shared fixture bootstrap for tests covering the Deluge updater module.
 */
abstract class DelugeAppTestCase extends TestCase
{
    /** @var array<int,string> */
    protected $logs = [];

    /** @var callable */
    protected $logger;

    protected function pmssSetUpDelugeFixture(string $prefix): void
    {
        $this->pmssAssignTempDirProperty('tempDir', $prefix, 0700);
        $this->logs = [];
        $this->logger = $this->pmssMakeArrayLogger($this->logs);
    }

    /** Apply a patch to a temporary source and return its result and exact bytes. */
    protected function pmssDelugePatchFixtureApply(string $filename, string $source, callable $patch, bool $dryRun = false): array
    {
        $path = $this->tempDir.'/'.$filename;
        file_put_contents($path, $source);
        $result = $patch($path, $dryRun, $this->logger);
        return [$result, (string) file_get_contents($path)];
    }

    /** Verify each patch refuses symlinks without modifying the target source. */
    protected function pmssAssertDelugePatchSymlinkRefused(string $filename, string $source, callable $patch): void
    {
        $realPath = $this->tempDir.'/real-'.$filename;
        $linkPath = $this->tempDir.'/'.$filename;
        file_put_contents($realPath, $source);
        $this->assertTrue(symlink($realPath, $linkPath), 'Expected symlink fixture creation');

        $this->assertFalse($patch($linkPath, false, $this->logger), 'Expected symlink path to be refused');
        $this->assertEquals($source, (string) file_get_contents($realPath), 'Symlink target must remain unchanged');
    }
}
