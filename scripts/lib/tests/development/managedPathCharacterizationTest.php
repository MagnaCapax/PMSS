<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ManagedPathCharacterizationTest extends TestCase
{
    public function testBackupHelperDelegatesToSharedWriter(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/managedPath.php');

        $this->assertStringContainsString(
            'pmssWriteManagedPathFile($path, $contents, $label, $writeLogger, $owner, $group, $mode',
            $src
        );
        $this->assertSame(1, substr_count($src, 'pmssAtomicWriteFile('));
        $this->assertSame(1, substr_count($src, 'pmssWriteManagedFile('));
    }
}
