<?php
require_once dirname(__DIR__).'/common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/environment.php';

use PMSS\Tests\TestCase;

class ManagedPathTest extends TestCase
{
    private function managedPathFixture(string $prefix): array
    {
        $root = $this->pmssMakeTempDir($prefix);
        return [$root, $root.'/managed.conf'];
    }

    public function testManagedPathAcceptsRegularFileTarget(): void
    {
        [, $path] = $this->managedPathFixture('pmss-env-write-');
        $messages = [];

        $this->assertTrue(\pmssManagedPathIsSafe($path, 'test target', $this->pmssMakeArrayLogger($messages)));
        $this->assertEquals([], $messages);
    }

    public function testManagedPathRejectsSymlinkedParentDirectory(): void
    {
        [$root] = $this->managedPathFixture('pmss-env-link-');
        [, $linkDir] = $this->pmssCreateSymlinkedDirectoryOrSkip($root.'/real', $root.'/link');
        $messages = [];

        $this->assertFalse(\pmssManagedPathIsSafe($linkDir.'/managed.conf', 'test target', $this->pmssMakeArrayLogger($messages)));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Unsafe test target directory'));
    }

    public function testManagedPathRejectsFileAsParent(): void
    {
        [$root] = $this->managedPathFixture('pmss-env-parent-file-');
        $parentFile = $root.'/not-a-dir';
        file_put_contents($parentFile, 'x');
        $messages = [];

        $this->assertFalse(\pmssManagedPathIsSafe($parentFile.'/managed.conf', 'test target', $this->pmssMakeArrayLogger($messages)));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Unsafe test target directory'));
    }

    public function testManagedWriteCreatesRegularFile(): void
    {
        [, $path] = $this->managedPathFixture('pmss-env-write-ok-');
        $messages = [];

        $this->assertTrue(\pmssWriteManagedPathFile($path, "alpha\n", 'test target', $this->pmssMakeArrayLogger($messages)));
        $this->assertEquals("alpha\n", file_get_contents($path));
        $this->assertEquals([], $messages);
    }

    public function testManagedWriteAppliesRequestedModeWhenMetadataProvided(): void
    {
        [, $path] = $this->managedPathFixture('pmss-env-write-meta-');
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
        [$root] = $this->managedPathFixture('pmss-env-target-link-');
        [$target, $link] = $this->pmssCreateSymlinkedFileOrSkip($root.'/target.conf', $root.'/managed.conf', "old\n");
        $messages = [];

        $this->assertFalse(\pmssWriteManagedPathFile($link, "new\n", 'test target', $this->pmssMakeArrayLogger($messages)));
        $this->assertEquals("old\n", file_get_contents($target));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Unsafe test target target'));
    }

    public function testManagedWriteWithBackupPreservesOriginalAndLogsSuccess(): void
    {
        [, $path] = $this->managedPathFixture('pmss-env-write-backup-');
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

    public function testManagedBackupSkipsPreExistingSymlinkCandidate(): void
    {
        [$root, $path] = $this->managedPathFixture('pmss-env-backup-symlink-');
        file_put_contents($path, "before\n");
        $outside = $root.'/outside.conf';
        $timestamp = '20260102030405';
        $blocked = \pmssManagedPathBackupCandidate($path, $timestamp, 0);
        $this->pmssCreateSymlinkedFileOrSkip($outside, $blocked, "outside\n");
        $messages = [];

        $backup = \pmssCreateManagedPathBackup($path, 'test target', $this->pmssMakeArrayLogger($messages), $timestamp);

        $this->assertEquals(\pmssManagedPathBackupCandidate($path, $timestamp, 1), $backup);
        $this->assertEquals("before\n", file_get_contents($backup));
        $this->assertEquals("outside\n", file_get_contents($outside));
        $this->assertEquals([], $messages);
    }

    public function testManagedBackupSkipsPreExistingRegularCandidate(): void
    {
        [, $path] = $this->managedPathFixture('pmss-env-backup-collision-');
        file_put_contents($path, "before\n");
        $timestamp = '20260102030406';
        $blocked = \pmssManagedPathBackupCandidate($path, $timestamp, 0);
        file_put_contents($blocked, "occupied\n");
        $messages = [];

        $backup = \pmssCreateManagedPathBackup($path, 'test target', $this->pmssMakeArrayLogger($messages), $timestamp);

        $this->assertEquals(\pmssManagedPathBackupCandidate($path, $timestamp, 1), $backup);
        $this->assertEquals("occupied\n", file_get_contents($blocked));
        $this->assertEquals("before\n", file_get_contents($backup));
        $this->assertEquals([], $messages);
    }

    public function testManagedBackupLogsWhenAllTimestampCandidatesExist(): void
    {
        [, $path] = $this->managedPathFixture('pmss-env-backup-full-');
        file_put_contents($path, "before\n");
        $timestamp = '20260102030407';
        for ($attempt = 0; $attempt < 10; $attempt++) {
            file_put_contents(\pmssManagedPathBackupCandidate($path, $timestamp, $attempt), "occupied\n");
        }
        $messages = [];

        $backup = \pmssCreateManagedPathBackup($path, 'test target', $this->pmssMakeArrayLogger($messages), $timestamp);

        $this->assertEquals('', $backup);
        $this->assertTrue($this->pmssMessagesContain($messages, 'timestamped backup paths already exist for '.$path));
    }

    public function testManagedWriteWithBackupRejectsSymlinkTarget(): void
    {
        [$root] = $this->managedPathFixture('pmss-env-write-backup-link-');
        [$target, $link] = $this->pmssCreateSymlinkedFileOrSkip($root.'/target.conf', $root.'/managed.conf', "before\n");
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
        [$root] = $this->managedPathFixture('pmss-env-remove-link-');
        [$target, $link] = $this->pmssCreateSymlinkedFileOrSkip($root.'/target.conf', $root.'/managed.conf', "old\n");
        $messages = [];

        $this->assertFalse(\pmssRemoveManagedPathFile($link, 'test target', $this->pmssMakeArrayLogger($messages)));
        $this->assertEquals("old\n", file_get_contents($target));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Unsafe test target target'));
    }

    public function testManagedRemoveLogsFailedUnlink(): void
    {
        [$root] = $this->managedPathFixture('pmss-env-remove-missing-');
        $path = $root.'/missing.conf';
        $messages = [];

        $this->assertFalse(\pmssRemoveManagedPathFile($path, 'test target', $this->pmssMakeArrayLogger($messages)));
        $this->assertTrue($this->pmssMessagesContain($messages, 'Unable to remove test target at '.$path));
    }
}
