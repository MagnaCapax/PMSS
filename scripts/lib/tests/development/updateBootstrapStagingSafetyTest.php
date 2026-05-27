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

    public function testAtomicSwapGuardRejectsSymlinkedTarget(): void
    {
        $tempRoot = $this->pmssMakeTempDir('pmss-update-swap-');
        $this->pmssEnsureFixtureDirectory($tempRoot.'/real-target');
        $this->pmssEnsureFixtureDirectory($tempRoot.'/staging');
        $this->pmssCreateSymlinkOrSkip($tempRoot.'/real-target', $tempRoot.'/target');

        $this->assertFalse(pmssIsSafeAtomicSwapDirectoryPath($tempRoot.'/target', $tempRoot.'/staging', $tempRoot.'/backup'));
    }

    public function testSnapshotPathGuardAcceptsOnlyRealPathsInsideSnapshot(): void
    {
        $root = $this->pmssMakeTempDir('pmss-update-snapshot-');
        $this->pmssWriteFile($root.'/scripts/update.php', '<?php echo "ok";');
        $this->pmssWriteFile($root.'/scripts/util/update-step2.php', '<?php echo "ok";');

        $error = null;
        $this->assertTrue(pmssIsSafeSnapshotPath($root, $root.'/scripts', 'directory', $error), (string) $error);
        $this->assertTrue(pmssIsSafeSnapshotPath($root, $root.'/scripts/update.php', 'file', $error), (string) $error);
        $this->assertTrue(pmssIsSafeSnapshotPath($root, $root.'/scripts/util', 'entry', $error), (string) $error);

        $this->assertFalse(pmssIsSafeSnapshotPath($root, $root.'/scripts', 'file', $error));
        $this->assertStringContainsString('not a file', (string) $error);
        $this->assertFalse(pmssIsSafeSnapshotPath($root, $root.'/missing.php', 'file', $error));
        $this->assertStringContainsString('missing', (string) $error);
    }

    public function testSnapshotPathGuardRejectsSymlinkedSourceRoots(): void
    {
        $root = $this->pmssMakeTempDir('pmss-update-snapshot-');
        $outsideRoot = $this->pmssMakeTempDir('pmss-update-snapshot-outside-');
        $this->pmssWriteFile($outsideRoot.'/keep.txt', 'keep');
        $this->pmssCreateSymlinkOrSkip($outsideRoot, $root.'/var');
        $this->pmssEnsureFixtureDirectory($root.'/scripts');
        $this->pmssWriteFile($outsideRoot.'/update-step2.php', '<?php echo "ok";');
        $this->pmssCreateSymlinkOrSkip($outsideRoot, $root.'/scripts/util');

        $error = null;
        $this->assertFalse(pmssIsSafeSnapshotPath($root, $root.'/var', 'directory', $error));
        $this->assertStringContainsString('symlink', (string) $error);
        $this->assertFalse(pmssIsSafeSnapshotPath($root, $root.'/scripts/util/update-step2.php', 'file', $error));
        $this->assertStringContainsString('symlink segment', (string) $error);
        $this->assertFalse(directoryHasContent($root.'/var'), 'snapshot content checks must not follow symlinked roots');
    }

    public function testSnapshotTreeLinkGuardAllowsRelativeSkeletonLinks(): void
    {
        $root = $this->pmssMakeTempDir('pmss-update-snapshot-');
        $this->pmssWriteFile($root.'/etc/skel/www/index.php', '<?php echo "ok";');
        $this->pmssCreateSymlinkOrSkip('../data', $root.'/etc/skel/www/data');
        $this->pmssCreateSymlinkOrSkip('../watch', $root.'/etc/skel/www/watch');

        $error = null;
        $this->assertTrue(pmssValidateSnapshotTreeLinks($root, $root.'/etc', $error), (string) $error);
    }

    public function testSnapshotTreeLinkGuardRejectsAbsoluteTargets(): void
    {
        $root = $this->pmssMakeTempDir('pmss-update-snapshot-');
        $this->pmssWriteFile($root.'/etc/skel/www/index.php', '<?php echo "ok";');
        $this->pmssCreateSymlinkOrSkip('/etc/passwd', $root.'/etc/skel/www/passwd');

        $error = null;
        $this->assertFalse(pmssValidateSnapshotTreeLinks($root, $root.'/etc', $error));
        $this->assertStringContainsString('unsafe target', (string) $error);
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

    public function testBootstrapFileWriteHelperWritesContentAndMode(): void
    {
        $root = $this->pmssMakeTempDir('pmss-update-write-');
        $path = $root.'/marker.txt';

        $this->assertTrue(pmssWriteBootstrapFile($path, 'marker', 'test marker', 0600, LOCK_EX));
        $this->assertSame('marker', (string) file_get_contents($path));

        $perms = fileperms($path);
        $this->assertTrue(is_int($perms), 'Expected marker permissions to be readable');
        $this->assertSame(0600, $perms & 0777);
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
