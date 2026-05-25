<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../common/updateBootstrapShim.php';

class UpdateBootstrapStagingSafetyTest extends TestCase
{
    public function testUpdaterRemovePathGuardAcceptsOnlyGeneratedUpdatePaths(): void
    {
        $tmpPrefix = rtrim(sys_get_temp_dir(), '/').'/pmss-update-';

        $this->assertTrue(pmssIsSafeUpdateRemovePath($tmpPrefix.'abc123'));
        $this->assertTrue(pmssIsSafeUpdateRemovePath('/scripts.pmss-staging-abc123'));
        $this->assertTrue(pmssIsSafeUpdateRemovePath('/scripts.pmss-backup-abc123'));
        $this->assertTrue(pmssIsSafeUpdateRemovePath('/etc/seedbox.pmss-staging-abc123'));
        $this->assertTrue(pmssIsSafeUpdateRemovePath('/etc/seedbox.pmss-backup-abc123'));

        $this->assertFalse(pmssIsSafeUpdateRemovePath(''));
        $this->assertFalse(pmssIsSafeUpdateRemovePath('/'));
        $this->assertFalse(pmssIsSafeUpdateRemovePath('/home'));
        $this->assertFalse(pmssIsSafeUpdateRemovePath('/scripts'));
        $this->assertFalse(pmssIsSafeUpdateRemovePath('/etc/seedbox'));
        $this->assertFalse(pmssIsSafeUpdateRemovePath($tmpPrefix));
        $this->assertFalse(pmssIsSafeUpdateRemovePath($tmpPrefix.'abc123/../escape'));
    }

    public function testAtomicSwapDirectoryKeepsPreviousTreeInBackup(): void
    {
        $root = $this->pmssMakeTempDir('pmss-update-swap-');
        $target = $root.'/target';
        $staging = $root.'/staging';
        $backup = $root.'/backup';

        $this->pmssEnsureFixtureDirectory($target);
        $this->pmssEnsureFixtureDirectory($staging);
        $this->pmssWriteFile($target.'/old.txt', 'old');
        $this->pmssWriteFile($staging.'/new.txt', 'new');

        pmssAtomicSwapDirectory($target, $staging, $backup, 'test tree');

        $this->assertTrue(is_file($target.'/new.txt'), 'staged tree should land at target');
        $this->assertTrue(is_file($backup.'/old.txt'), 'previous target should remain in backup');
        $this->assertFalse(is_dir($staging), 'staging path should be consumed by rename');
    }
}
