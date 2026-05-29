<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class RecreateUserSafetyGuardTest extends TestCase
{
    public function testRejectsSymlinkedHomeAndBackupPaths(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/recreateUser.php', [
            "pmssRequireSafeRecreateUserPath(\$homeDir, 'home');",
            "pmssRequireSafeRecreateUserPath(\$backupDir, 'backup');",
            'Refusing to operate on symlinked',
        ]);
    }

    public function testRejectsUnexpectedResolvedHomePath(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/recreateUser.php', [
            '$realHome = realpath($homeDir);',
            'Refusing to operate on unexpected home path',
        ]);
    }

    public function testEnsureDirChecksMkdirFailure(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/recreateUser.php', [
            '!@mkdir($dir, 0755, true) && !is_dir($dir)',
            'Unable to create required directory',
        ]);
    }

    public function testOwnershipValidationChecksStatFailure(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/recreateUser.php', [
            '$stat = @stat($homeDir);',
            'Validation failed: unable to stat homeDir',
        ]);
    }
}
