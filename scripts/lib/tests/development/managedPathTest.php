<?php
require_once dirname(__DIR__).'/common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/environment.php';

use PMSS\Tests\TestCase;

class ManagedPathTest extends TestCase
{
    public function testManagedPathAcceptsRegularFileTarget(): void
    {
        $root = $this->pmssMakeTempDir('pmss-env-write-');
        $path = $root.'/managed.conf';
        $messages = [];

        $this->assertTrue(\pmssManagedPathIsSafe($path, 'test target', $this->pmssMakeArrayLogger($messages)));
        $this->assertEquals([], $messages);
    }

    public function testManagedPathRejectsSymlinkedParentDirectory(): void
    {
        $root = $this->pmssMakeTempDir('pmss-env-link-');
        $realDir = $root.'/real';
        $this->pmssEnsureDir($realDir, 0755);
        $linkDir = $root.'/link';
        $this->pmssCreateSymlinkOrSkip($realDir, $linkDir);
        $messages = [];

        $this->assertFalse(\pmssManagedPathIsSafe($linkDir.'/managed.conf', 'test target', $this->pmssMakeArrayLogger($messages)));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Unsafe test target directory'));
    }

    public function testManagedPathRejectsFileAsParent(): void
    {
        $root = $this->pmssMakeTempDir('pmss-env-parent-file-');
        $parentFile = $root.'/not-a-dir';
        file_put_contents($parentFile, 'x');
        $messages = [];

        $this->assertFalse(\pmssManagedPathIsSafe($parentFile.'/managed.conf', 'test target', $this->pmssMakeArrayLogger($messages)));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Unsafe test target directory'));
    }

    public function testManagedWriteCreatesRegularFile(): void
    {
        $root = $this->pmssMakeTempDir('pmss-env-write-ok-');
        $path = $root.'/managed.conf';
        $messages = [];

        $this->assertTrue(\pmssWriteManagedPathFile($path, "alpha\n", 'test target', $this->pmssMakeArrayLogger($messages)));
        $this->assertEquals("alpha\n", file_get_contents($path));
        $this->assertEquals([], $messages);
    }

    public function testManagedWriteAppliesRequestedModeWhenMetadataProvided(): void
    {
        $root = $this->pmssMakeTempDir('pmss-env-write-meta-');
        $path = $root.'/managed.conf';
        $messages = [];

        $this->assertTrue(
            \pmssWriteManagedPathFile($path, "alpha\n", 'test target', $this->pmssMakeArrayLogger($messages), 'root', 'root', 0600)
        );
        $this->assertEquals("alpha\n", file_get_contents($path));
        $this->assertEquals(0600, fileperms($path) & 0777);
        $this->assertEquals([], $messages);
    }

    public function testManagedWriteRejectsSymlinkTarget(): void
    {
        $root = $this->pmssMakeTempDir('pmss-env-target-link-');
        $target = $root.'/target.conf';
        file_put_contents($target, "old\n");
        $link = $root.'/managed.conf';
        $this->pmssCreateSymlinkOrSkip($target, $link);
        $messages = [];

        $this->assertFalse(\pmssWriteManagedPathFile($link, "new\n", 'test target', $this->pmssMakeArrayLogger($messages)));
        $this->assertEquals("old\n", file_get_contents($target));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Unsafe test target target'));
    }

    public function testManagedWriteWithBackupPreservesOriginalAndLogsSuccess(): void
    {
        $root = $this->pmssMakeTempDir('pmss-env-write-backup-');
        $path = $root.'/managed.conf';
        file_put_contents($path, "before\n");
        $messages = [];

        $this->assertTrue(
            \pmssWriteManagedPathFileWithBackup(
                $path,
                ['after', 'value'],
                'test target',
                $this->pmssMakeArrayLogger($messages),
                true
            )
        );

        $this->assertEquals("after\nvalue\n", file_get_contents($path));
        $backups = glob($path.'.pmss-backup-*') ?: [];
        $this->assertEquals(1, count($backups), 'expected exactly one backup');
        $this->assertEquals("before\n", file_get_contents($backups[0]));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Wrote updated '.$path.' (backup '));
    }

    public function testManagedWriteWithBackupRejectsSymlinkTarget(): void
    {
        $root = $this->pmssMakeTempDir('pmss-env-write-backup-link-');
        $target = $root.'/target.conf';
        file_put_contents($target, "before\n");
        $link = $root.'/managed.conf';
        $this->pmssCreateSymlinkOrSkip($target, $link);
        $messages = [];

        $this->assertFalse(
            \pmssWriteManagedPathFileWithBackup(
                $link,
                ['after'],
                'test target',
                $this->pmssMakeArrayLogger($messages),
                true
            )
        );
        $this->assertEquals("before\n", file_get_contents($target));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Unsafe test target target'));
        $this->assertEquals([], glob($link.'.pmss-backup-*') ?: []);
    }

    public function testManagedRemoveRejectsSymlinkTarget(): void
    {
        $root = $this->pmssMakeTempDir('pmss-env-remove-link-');
        $target = $root.'/target.conf';
        file_put_contents($target, "old\n");
        $link = $root.'/managed.conf';
        $this->pmssCreateSymlinkOrSkip($target, $link);
        $messages = [];

        $this->assertFalse(\pmssRemoveManagedPathFile($link, 'test target', $this->pmssMakeArrayLogger($messages)));
        $this->assertEquals("old\n", file_get_contents($target));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Unsafe test target target'));
    }

    public function testManagedRemoveLogsFailedUnlink(): void
    {
        $root = $this->pmssMakeTempDir('pmss-env-remove-missing-');
        $path = $root.'/missing.conf';
        $messages = [];

        $this->assertFalse(\pmssRemoveManagedPathFile($path, 'test target', $this->pmssMakeArrayLogger($messages)));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Unable to remove test target at '.$path));
    }
}
