<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class ManagedPathCharacterizationTest extends TestCase
{
    public function testBackupHelperDelegatesToSharedWriter(): void
    {
        $this->pmssAssertRepoFileContainsString(
            'scripts/lib/update/managedPath.php',
            'pmssWriteManagedPathFile($path, $contents, $label, $logger, $owner, $group, $mode',
        );
        $this->pmssAssertRepoFileSubstringCount('scripts/lib/update/managedPath.php', 'pmssAtomicWriteFile(', 1);
        $this->pmssAssertRepoFileSubstringCount('scripts/lib/update/managedPath.php', 'pmssWriteManagedFile(', 1);
    }
}
