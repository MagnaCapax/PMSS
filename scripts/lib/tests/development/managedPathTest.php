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

    public function testCompletedVersionMarkerUsesTrimmedVersion(): void
    {
        [$root] = $this->managedPathFixture('pmss-completed-version-');
        $versionPath = $root.'/version';
        $completedPath = $root.'/version.completed';
        file_put_contents($versionPath, "  git/main@2026-10-18  \n");
        $messages = [];

        $this->assertTrue(\pmssWriteCompletedVersionMarker($versionPath, $completedPath, $this->pmssMakeArrayLogger($messages)));
        $this->assertSame("git/main@2026-10-18\n", file_get_contents($completedPath));
        $this->assertSame(0644, fileperms($completedPath) & 0777);
        $this->assertSame([], $messages);
    }

    public function testCompletedVersionMarkerSkipsMissingAndEmptyVersion(): void
    {
        [$root] = $this->managedPathFixture('pmss-completed-empty-');
        $versionPath = $root.'/version';
        $completedPath = $root.'/version.completed';
        $messages = [];
        $logger = $this->pmssMakeArrayLogger($messages);

        $this->assertFalse(\pmssWriteCompletedVersionMarker($versionPath, $completedPath, $logger));
        $this->assertFalse(file_exists($completedPath));
        file_put_contents($versionPath, " \n\t");
        $this->assertFalse(\pmssWriteCompletedVersionMarker($versionPath, $completedPath, $logger));
        $this->assertFalse(file_exists($completedPath));
        $this->assertSame(2, count($messages));
        $this->assertTrue($this->pmssMessagesContain($messages, '[WARN] Cannot write completed version marker'));
    }

    public function testCompletedVersionMarkerMatchesPhase2DryRunWrite(): void
    {
        [$root] = $this->managedPathFixture('pmss-completed-dry-run-');
        $versionPath = $root.'/version';
        $completedPath = $root.'/version.completed';
        file_put_contents($versionPath, "git/main\n");
        $messages = [];

        $this->pmssWithEnv(['PMSS_DRY_RUN' => '1'], function () use ($versionPath, $completedPath, &$messages): void {
            $this->assertTrue(\pmssWriteCompletedVersionMarker($versionPath, $completedPath, $this->pmssMakeArrayLogger($messages)));
        });
        $this->assertSame("git/main\n", file_get_contents($completedPath));
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

    public function testManagedBackupRejectsSymlinkSource(): void
    {
        [$root, $path] = $this->managedPathFixture('pmss-env-backup-source-link-');
        [$target, $link] = $this->pmssCreateSymlinkedFileOrSkip($root.'/outside.conf', $path, "outside\n");
        $messages = [];

        $this->assertSame('', \pmssCreateManagedPathBackup($link, 'test target', $this->pmssMakeArrayLogger($messages), '20260102030408'));
        $this->assertSame("outside\n", file_get_contents($target));
        $this->assertSame([], glob($link.'.pmss-backup-*') ?: []);
        $this->assertTrue($this->pmssMessagesContain($messages, 'Unsafe test target target'));
    }

    public function testManagedBackupRejectsMissingSource(): void
    {
        [, $path] = $this->managedPathFixture('pmss-env-backup-source-missing-');
        $messages = [];

        $this->assertSame('', \pmssCreateManagedPathBackup($path, 'test target', $this->pmssMakeArrayLogger($messages), '20260102030409'));
        $this->assertSame([], glob($path.'.pmss-backup-*') ?: []);
        $this->assertTrue($this->pmssMessagesContain($messages, 'Unable to open test target for backup'));
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

    public function testManagedWritePreservesOriginalWhenBackupCandidatesAreFull(): void
    {
        [, $path] = $this->managedPathFixture('pmss-env-write-backup-full-');
        file_put_contents($path, "before\n");
        $timestamp = date('YmdHis');
        for ($attempt = 0; $attempt < 10; $attempt++) {
            file_put_contents(\pmssManagedPathBackupCandidate($path, $timestamp, $attempt), "occupied\n");
        }
        $messages = [];

        $this->assertFalse(\pmssWriteManagedPathFileWithBackup(
            $path, ['after'], 'test target', $this->pmssMakeArrayLogger($messages)
        ));
        $this->assertEquals("before\n", file_get_contents($path));
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
