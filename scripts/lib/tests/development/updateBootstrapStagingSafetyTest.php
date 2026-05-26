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

    public function testDirectoryClearGuardAcceptsOnlySkeletonAndGeneratedTempPaths(): void
    {
        $tmpPrefix = rtrim(sys_get_temp_dir(), '/').'/pmss-update-clear-';

        $this->assertTrue(pmssIsSafeDirectoryContentsClearPath('/etc/skel'));
        $this->assertTrue(pmssIsSafeDirectoryContentsClearPath($tmpPrefix.'abc123'));

        $this->assertFalse(pmssIsSafeDirectoryContentsClearPath(''));
        $this->assertFalse(pmssIsSafeDirectoryContentsClearPath('/'));
        $this->assertFalse(pmssIsSafeDirectoryContentsClearPath('/etc'));
        $this->assertFalse(pmssIsSafeDirectoryContentsClearPath('/home'));
        $this->assertFalse(pmssIsSafeDirectoryContentsClearPath($tmpPrefix));
        $this->assertFalse(pmssIsSafeDirectoryContentsClearPath($tmpPrefix.'abc123/../escape'));
    }

    public function testAtomicSwapGuardAcceptsOnlyKnownSwapPairs(): void
    {
        $tempRoot = $this->pmssMakeTempDir('pmss-update-swap-');

        $this->assertTrue(pmssIsSafeAtomicSwapDirectoryPath('/scripts', '/scripts.pmss-staging-abc123', '/scripts.pmss-backup-abc123'));
        $this->assertTrue(pmssIsSafeAtomicSwapDirectoryPath('/etc/seedbox', '/etc/seedbox.pmss-staging-abc123', '/etc/seedbox.pmss-backup-abc123'));
        $this->assertTrue(pmssIsSafeAtomicSwapDirectoryPath($tempRoot.'/target', $tempRoot.'/staging', $tempRoot.'/backup'));

        $this->assertFalse(pmssIsSafeAtomicSwapDirectoryPath('', '/scripts.pmss-staging-abc123', '/scripts.pmss-backup-abc123'));
        $this->assertFalse(pmssIsSafeAtomicSwapDirectoryPath('/home', '/scripts.pmss-staging-abc123', '/scripts.pmss-backup-abc123'));
        $this->assertFalse(pmssIsSafeAtomicSwapDirectoryPath('/scripts', '/tmp/staging', '/scripts.pmss-backup-abc123'));
        $this->assertFalse(pmssIsSafeAtomicSwapDirectoryPath('/scripts', '/scripts.pmss-staging-abc123', '/tmp/backup'));
        $this->assertFalse(pmssIsSafeAtomicSwapDirectoryPath($tempRoot.'/target', $tempRoot.'/staging', $tempRoot.'/../backup'));
    }

    public function testNestedScriptsLayoutRemoveGuardAcceptsOnlyKnownPaths(): void
    {
        $tempRoot = $this->pmssMakeTempDir('pmss-update-nested-scripts-');

        $this->assertTrue(pmssIsSafeNestedScriptsLayoutRemovePath('/scripts/scripts'));
        $this->assertTrue(pmssIsSafeNestedScriptsLayoutRemovePath($tempRoot.'/scripts/scripts'));

        $this->assertFalse(pmssIsSafeNestedScriptsLayoutRemovePath(''));
        $this->assertFalse(pmssIsSafeNestedScriptsLayoutRemovePath('/'));
        $this->assertFalse(pmssIsSafeNestedScriptsLayoutRemovePath('/scripts'));
        $this->assertFalse(pmssIsSafeNestedScriptsLayoutRemovePath('/etc/seedbox'));
        $this->assertFalse(pmssIsSafeNestedScriptsLayoutRemovePath($tempRoot.'/scripts'));
        $this->assertFalse(pmssIsSafeNestedScriptsLayoutRemovePath($tempRoot.'/scripts/../scripts'));
    }

    public function testClearDirectoryContentsRemovesEntriesWithoutFollowingSymlinks(): void
    {
        $root = $this->pmssMakeTempDir('pmss-update-clear-');
        $outsideRoot = $this->pmssMakeTempDir('pmss-update-clear-outside-');
        $outsideFile = $this->pmssWriteFile($outsideRoot.'/keep.txt', 'keep');

        $this->pmssWriteFile($root.'/visible.txt', 'visible');
        $this->pmssWriteFile($root.'/.hidden', 'hidden');
        $this->pmssWriteFile($root.'/nested/child.txt', 'child');
        $this->pmssCreateSymlinkOrSkip($outsideFile, $root.'/outside-link');

        $error = null;
        $this->assertTrue(pmssClearDirectoryContents($root, 'test tree', $error), (string) $error);

        $this->assertTrue(is_dir($root), 'clear must leave the root directory in place');
        $entries = array_values(array_diff(scandir($root) ?: [], ['.', '..']));
        $this->assertSame([], $entries, 'clear must remove every child entry');
        $this->assertTrue(is_file($outsideFile), 'clear must unlink symlinks without following them');
    }

    public function testClearDirectoryContentsRefusesUnsafePath(): void
    {
        $error = null;

        $this->assertFalse(pmssClearDirectoryContents('/home', 'home tree', $error));
        $this->assertStringContainsString('Refusing unsafe home tree clear path', (string) $error);
    }

    public function testRemoveFileRefusesDirectoriesAndUnlinksSymlinksOnly(): void
    {
        $root = $this->pmssMakeTempDir('pmss-update-remove-file-');
        $target = $this->pmssWriteFile($root.'/target.txt', 'target');
        $link = $root.'/target-link';
        $this->pmssCreateSymlinkOrSkip($target, $link);

        $error = null;
        $this->assertTrue(pmssRemoveFile($link, 'test link', $error), (string) $error);
        $this->assertFalse(is_link($link), 'symlink should be unlinked');
        $this->assertTrue(is_file($target), 'symlink target should remain');

        $this->assertFalse(pmssRemoveFile($root, 'test directory', $error));
        $this->assertStringContainsString('Refusing to unlink directory', (string) $error);
    }

    public function testNestedScriptsLayoutRemovalDoesNotFollowSymlinks(): void
    {
        $root = $this->pmssMakeTempDir('pmss-update-nested-scripts-');
        $nested = $root.'/scripts/scripts';
        $outsideRoot = $this->pmssMakeTempDir('pmss-update-nested-scripts-outside-');
        $outsideFile = $this->pmssWriteFile($outsideRoot.'/keep.txt', 'keep');

        $this->pmssWriteFile($nested.'/nested.txt', 'nested');
        $this->pmssCreateSymlinkOrSkip($outsideFile, $nested.'/outside-link');

        $this->assertTrue(pmssRemoveNestedScriptsLayout($nested));
        $this->assertFalse(file_exists($nested), 'nested scripts layout should be removed');
        $this->assertTrue(is_file($outsideFile), 'nested layout removal must not follow symlink targets');
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
