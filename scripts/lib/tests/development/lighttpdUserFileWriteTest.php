<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/lighttpd/userFileWrite.php';
require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/trackerCleaner/log.php';

class LighttpdUserFileWriteTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-lighttpd-user-write-']; }

    public function testRootAccountCommandUsesNoPamIsolatedRunner(): void
    {
        $command = \pmssAccountPathShellCommand(0, 1001, 'alice', 'touch /tmp/example');
        $this->assertStringContainsAllStrings(['env -i', 'setsid runuser', '-u', "'alice'"], $command);
        $this->assertFalse(strpos($command, 'su -s') !== false);
        $this->assertFalse(strpos($command, '</dev/null') !== false);
        $this->assertFalse(strpos($command, '--pty') !== false);
        $this->assertSame("sh -c 'true'", \pmssAccountPathShellCommand(1001, 1001, 'alice', 'true'));
    }

    public function testRootAccountCommandDeliversStandardInputToTheAccountCommand(): void
    {
        $stub = $this->tempDir.'/stub';
        $this->assertTrue(@mkdir($stub, 0700));
        // Stand-in for runuser: drop "-u USER --" and run the rest as this user.
        file_put_contents($stub.'/runuser', "#!/bin/sh\nshift 3\nexec \"\$@\"\n");
        chmod($stub.'/runuser', 0700);
        $received = $this->tempDir.'/received';
        $command = \pmssAccountPathShellCommand(0, 1001, 'alice', 'cat > '.escapeshellarg($received));
        $command = str_replace('PATH=/usr/sbin:/usr/bin:/sbin:/bin',
            'PATH='.escapeshellarg($stub.':/usr/sbin:/usr/bin:/sbin:/bin'), $command);
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $this->assertTrue(is_resource($process));
        fwrite($pipes[0], "user:hash\n");
        fclose($pipes[0]);
        $this->assertSame(0, proc_close($process));
        $this->assertSame("user:hash\n", (string) @file_get_contents($received));
    }

    public function testAccountFileReplacementKeepsContentAndMode(): void
    {
        $home = $this->tempDir.'/account';
        $this->assertTrue(@mkdir($home));
        $path = $home.'/config';
        $owner = $this->pmssCurrentOwner();

        $this->assertTrue(\pmssReplaceAccountFile($owner, $home, $path, "first\n", 0640));
        $this->assertTrue(\pmssReplaceAccountFile($owner, $home, $path, "second\n", 0600));
        $this->assertSame("second\n", file_get_contents($path));
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame([], glob($home.'/config.pmss-tmp-*'));
    }

    public function testAccountFileWriteFailureLeavesExistingBytesAndMode(): void
    {
        $home = $this->pmssEnsureDir($this->tempDir.'/account');
        $path = $this->pmssWriteFile($home.'/config', 'original');
        chmod($path, 0640);
        chmod($home, 0555);
        try {
            $this->assertFalse(\pmssReplaceAccountFile($this->pmssCurrentOwner(), $home, $path, 'new', 0600));
            clearstatcache(true, $path);
            $this->assertSame('original', file_get_contents($path));
            $this->assertSame(0640, fileperms($path) & 0777);
        } finally {
            chmod($home, 0755);
        }
    }

    public function testAccountFileReplacementRejectsPathsOutsideHome(): void
    {
        $home = $this->tempDir.'/account';
        $this->assertTrue(@mkdir($home));
        $outside = $this->tempDir.'/outside';
        $this->assertFalse(\pmssReplaceAccountFile($this->pmssCurrentOwner(), $home, $outside, 'new', 0600));
        $this->assertFalse(file_exists($outside));
    }

    public function testAccountFileReplacementRejectsLinkedTarget(): void
    {
        $home = $this->tempDir.'/account';
        $this->assertTrue(@mkdir($home));
        $this->assertTrue(@file_put_contents($home.'/original', 'kept') !== false);
        $this->assertTrue(@symlink($home.'/original', $home.'/linked'));
        $this->assertFalse(\pmssReplaceAccountFile($this->pmssCurrentOwner(), $home, $home.'/linked', 'new', 0600));
        $this->assertSame('kept', file_get_contents($home.'/original'));
    }

    public function testLegacyOwnerOptionStillRejectsLinkedTarget(): void
    {
        $home = $this->pmssEnsureDir($this->tempDir.'/account');
        $target = $this->pmssWriteFile($this->tempDir.'/outside', 'kept');
        chmod($target, 0600);
        $before = lstat($target);
        $this->pmssCreateSymlinkOrSkip($target, $home.'/linked');

        $this->assertFalse(\pmssReplaceAccountFile(
            $this->pmssCurrentOwner(), $home, $home.'/linked', 'new', 0644, true
        ));
        clearstatcache(true, $target);
        $after = lstat($target);
        $this->assertSame($before['uid'], $after['uid']);
        $this->assertSame($before['mode'] & 07777, $after['mode'] & 07777);
        $this->assertSame('kept', file_get_contents($target));
    }

    public function testAccountFileReplacementRejectsMissingParentAndBadMode(): void
    {
        $home = $this->tempDir.'/account';
        $this->assertTrue(@mkdir($home));
        $owner = $this->pmssCurrentOwner();
        $this->assertFalse(\pmssReplaceAccountFile($owner, $home, $home.'/missing/file', 'new', 0600));
        $this->assertFalse(\pmssReplaceAccountFile($owner, $home, $home.'/config', 'new', 01000));
        $this->assertFalse(file_exists($home.'/config'));
    }

    public function testAccountFileReplacementRejectsUnknownAccountAndDirectoryTarget(): void
    {
        $home = $this->tempDir.'/account';
        $this->assertTrue(@mkdir($home));
        $this->assertTrue(@mkdir($home.'/dir'));
        $this->assertFalse(\pmssReplaceAccountFile('pmss-no-such-account-123', $home, $home.'/config', 'new', 0600));
        $this->assertFalse(\pmssReplaceAccountFile($this->pmssCurrentOwner(), $home, $home.'/dir', 'new', 0600));
    }

    public function testAccountFileLegacyFallbackKeepsNormalModeAndRejectsLinks(): void
    {
        $home = $this->tempDir.'/account';
        $this->assertTrue(@mkdir($home));
        $owner = $this->pmssCurrentOwner();
        $path = $home.'/marker';
        $this->assertTrue(\pmssReplaceAccountFileWithLegacyFallback($owner, $home, $path, 'first', 0644));
        $this->assertTrue(\pmssReplaceAccountFileWithLegacyFallback($owner, $home, $path, 'second', 0600));
        $this->assertSame('second', file_get_contents($path));
        $this->assertSame(0600, fileperms($path) & 0777);

        $this->assertTrue(@symlink($path, $home.'/linked'));
        $this->assertFalse(\pmssReplaceAccountFileWithLegacyFallback($owner, $home, $home.'/linked', 'bad', 0600));
        $this->assertFalse(\pmssReplaceAccountFileWithLegacyFallback($owner, $home, $this->tempDir.'/outside', 'bad', 0600));
        $this->assertSame('second', file_get_contents($path));
    }

    public function testAccountPathRunCreatesOnlyInsideOwnedHome(): void
    {
        $home = $this->tempDir.'/account';
        $this->assertTrue(@mkdir($home));
        $owner = $this->pmssCurrentOwner();
        $directory = $home.'/.bin';

        $this->assertTrue(\pmssAccountPathRun($owner, $home, [$directory], 'mkdir -m 750 -- '.escapeshellarg($directory)));
        $this->assertTrue(is_dir($directory));
        $this->assertSame(0750, fileperms($directory) & 0777);
        $outside = $this->tempDir.'/outside';
        $this->assertFalse(\pmssAccountPathRun($owner, $home, [$outside], 'mkdir -- '.escapeshellarg($outside)));
        $this->assertFalse(is_dir($outside));
    }

    public function testAccountPathRunStartsOutsideTheCallerDirectory(): void
    {
        $home = $this->pmssEnsureDir($this->tempDir.'/account-cwd');
        $owner = $this->pmssCurrentOwner();
        // The caller's directory may be unreadable to the account (root runs from /root).
        $locked = $this->pmssEnsureDir($this->tempDir.'/locked');
        $previous = getcwd();
        $this->assertTrue(chdir($locked));
        chmod($locked, 0);
        try {
            $this->assertTrue(\pmssAccountPathRun($owner, $home, [$home.'/cwd'], 'pwd > '.escapeshellarg($home.'/cwd')));
            $this->assertTrue(\pmssAccountPathRun($owner, $home, [$home],
                'find '.escapeshellarg($home).' -maxdepth 0 -type d -exec true {} +'));
        } finally {
            chmod($locked, 0700);
            chdir($previous);
        }
        $this->assertSame("/\n", file_get_contents($home.'/cwd'));
    }

    public function testAccountPathRunBoundsClosedOutputAndKeepsDefaultUnchanged(): void
    {
        $home = $this->pmssEnsureDir($this->tempDir.'/account-timeout');
        $owner = $this->pmssCurrentOwner();
        $timedOut = false;
        $started = microtime(true);
        $this->assertFalse(\pmssAccountPathRun($owner, $home, [$home.'/target'],
            'exec 1>&- 2>&-; sleep 30', null, 1, $timedOut));
        $this->assertTrue($timedOut);
        $this->assertTrue(microtime(true) - $started < 1 + PMSS_COMMAND_TIMEOUT_BACKSTOP_GRACE_SECONDS + 3);
        $this->assertTrue(\pmssAccountPathRun($owner, $home, [$home.'/target'], 'true'));
        $this->assertTrue(\pmssAccountPathRun($owner, $home, [$home.'/target'],
            'cat > '.escapeshellarg($home.'/target'), 'bounded input', 1, $timedOut));
        $this->assertFalse($timedOut);
        $this->assertSame('bounded input', file_get_contents($home.'/target'));
    }

    public function testAccountFileMetadataConvergesOnlySafeOwnedFile(): void
    {
        $home = $this->tempDir.'/account';
        $this->assertTrue(@mkdir($home));
        $path = $home.'/custom';
        $this->assertTrue(@file_put_contents($path, 'kept') !== false);
        $this->assertTrue(@chmod($path, 0750));
        $owner = $this->pmssCurrentOwner();

        \pmssAccountFileApplyMetadata($owner, $home, $path, 0640);
        clearstatcache(true, $path);
        $this->assertSame(0640, fileperms($path) & 0777);
        $this->assertSame('kept', file_get_contents($path));
        $this->assertTrue(@symlink($path, $home.'/linked'));
        \pmssAccountFileApplyMetadata($owner, $home, $home.'/linked', 0600);
        \pmssAccountFileApplyMetadata($owner, $home, $path, 01000);
        clearstatcache(true, $path);
        $this->assertSame(0640, fileperms($path) & 0777);
    }

    public function testOwnershipMetadataUsesEntryAwareCalls(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/lighttpd/userFileWrite.php');
        $this->assertStringContainsString('$ownerSet = @lchown($path, $owner);', $source);
        $this->assertStringContainsString('return @lchgrp($path, $group) && $ownerSet;', $source);
        // Link-following ownership calls live only in the opened-inode branch of one helper.
        $this->assertSame(1, substr_count($source, '@chown('));
        $this->assertSame(1, substr_count($source, '@chgrp('));
        $this->assertOrderedStrings(['if (pmssUserFileOpenedPathIs($path)) {', '@chown($path, $owner)', '@lchown($path, $owner)'], $source);
    }

    public function testOpenedPathRecognitionIsExact(): void
    {
        $this->assertTrue(\pmssUserFileOpenedPathIs('/proc/self/fd/7'));
        foreach (['/proc/self/fd/', '/proc/self/fd/7/x', '/proc/self/fd/7a', '/proc/1/fd/7', "/proc/self/fd/7\n", 'proc/self/fd/7', '/srv/managed/.htpasswd'] as $path) {
            $this->assertFalse(\pmssUserFileOpenedPathIs($path), $path);
        }
    }

    public function testApplyMetadataRefusesLinkedEntriesAndConvergesRegularFiles(): void
    {
        $outside = $this->tempDir.'/outside';
        $this->pmssWriteFile($outside, 'kept');
        $this->assertTrue(chmod($outside, 0644));
        $directory = $this->tempDir.'/user';
        $this->assertTrue(@mkdir($directory));
        $this->assertTrue(@symlink($outside, $directory.'/symlinked'));
        $this->assertTrue(@link($outside, $directory.'/hardlinked'));

        foreach (['symlinked', 'hardlinked', 'missing'] as $name) {
            \pmssUserFileApplyMetadata($directory.'/'.$name, $this->pmssCurrentOwner(), 0600);
            clearstatcache();
            $this->assertSame(0644, fileperms($outside) & 0777, $name);
        }
        $this->assertSame('kept', file_get_contents($outside));

        $regular = $directory.'/regular';
        $this->pmssWriteFile($regular, 'content');
        $this->assertTrue(chmod($regular, 0644));
        \pmssUserFileApplyMetadata($regular, $this->pmssCurrentOwner(), 0600);
        clearstatcache(true, $regular);
        $this->assertSame(0600, fileperms($regular) & 0777);
        $this->assertSame('content', file_get_contents($regular));
    }

    public function testAppendUserFileWritesNewFile(): void
    {
        $path = $this->tempDir.'/user/.lighttpd/.htpasswd';
        @mkdir(dirname($path), 0755, true);

        $this->assertTrue(\pmssAppendUserFile($path, "user:hash\n", $this->pmssCurrentOwner(), 0640));
        $this->assertEquals("user:hash\n", file_get_contents($path));
        $this->assertEquals(0640, fileperms($path) & 0777);
    }

    public function testAppendUserFilePreservesExistingContent(): void
    {
        $path = $this->tempDir.'/user/.lighttpd/.htpasswd';
        $this->pmssWriteFile($path, "first:hash\n");

        $this->assertTrue(\pmssAppendUserFile($path, "second:hash\n", $this->pmssCurrentOwner(), 0640));
        $this->assertEquals("first:hash\nsecond:hash\n", file_get_contents($path));
    }

    public function testAppendUserFileRejectsSymlinkTarget(): void
    {
        [$realPath, $linkPath] = $this->pmssCreateSymlinkedFileOrSkip($this->tempDir.'/real.htpasswd', $this->tempDir.'/link.htpasswd', "user:hash\n");

        $this->assertFalse(\pmssAppendUserFile($linkPath, "other:hash\n", $this->pmssCurrentOwner(), 0640));
        $this->assertEquals("user:hash\n", file_get_contents($realPath));
    }

    public function testAppendUserFileRefusesLinkedTargetsWithoutTouchingThem(): void
    {
        $outside = $this->tempDir.'/outside.htpasswd';
        $this->pmssWriteFile($outside, "user:hash\n");
        $this->assertTrue(chmod($outside, 0644));
        $directory = $this->tempDir.'/user';
        $this->assertTrue(@mkdir($directory));
        $this->assertTrue(@symlink($outside, $directory.'/symlinked'));
        $this->assertTrue(@link($outside, $directory.'/hardlinked'));
        $this->assertTrue(@symlink($this->tempDir.'/created-elsewhere', $directory.'/dangling'));

        foreach (['symlinked', 'hardlinked', 'dangling'] as $name) {
            $this->assertFalse(\pmssAppendUserFile($directory.'/'.$name, "other:hash\n", $this->pmssCurrentOwner(), 0600), $name);
            clearstatcache();
            $this->assertSame("user:hash\n", file_get_contents($outside), $name);
            $this->assertSame(0644, fileperms($outside) & 0777, $name);
        }
        $this->assertFalse(file_exists($this->tempDir.'/created-elsewhere'));
        $this->assertSame([], glob($directory.'/*.pmss-tmp-*'));
    }

    public function testAppendUserFileRejectsMissingParentDirectory(): void
    {
        $path = $this->tempDir.'/missing/.lighttpd/.htpasswd';

        $this->assertFalse(\pmssAppendUserFile($path, "user:hash\n", $this->pmssCurrentOwner(), 0640));
        $this->assertFalse(file_exists($path));
    }

    public function testAppendUserFileRejectsRelativePath(): void
    {
        $this->assertFalse(\pmssAppendUserFile('relative.htpasswd', "user:hash\n", $this->pmssCurrentOwner(), 0640));
        $this->assertFalse(file_exists('relative.htpasswd'));
    }

    public function testAppendUserFileRejectsTraversalSegment(): void
    {
        $managedDir = $this->tempDir.'/user/.lighttpd';
        $outsideDir = $this->tempDir.'/user/outside';
        @mkdir($managedDir, 0755, true);
        @mkdir($outsideDir, 0755, true);

        $path = $managedDir.'/../outside/.htpasswd';
        $this->assertFalse(\pmssAppendUserFile($path, "user:hash\n", $this->pmssCurrentOwner(), 0640));
        $this->assertFalse(file_exists($outsideDir.'/.htpasswd'));
    }

    public function testAccountAppendPreservesExistingLinesAndMode(): void
    {
        $home = $this->tempDir.'/account';
        $this->assertTrue(@mkdir($home));
        $path = $home.'/.htpasswd';
        $owner = $this->pmssCurrentOwner();

        $this->assertTrue(\pmssAppendAccountFile($owner, $home, $path, "first:hash\n", 0640));
        $this->assertTrue(\pmssAppendAccountFile($owner, $home, $path, "second:hash\n", 0640));
        $this->assertSame("first:hash\nsecond:hash\n", file_get_contents($path));
        $this->assertSame(0640, fileperms($path) & 0777);
    }

    public function testAccountAppendFailsWithinBoundWhenLockIsHeld(): void
    {
        $home = $this->pmssEnsureDir($this->tempDir.'/account');
        $path = $this->pmssWriteFile($home.'/log', 'old');
        $holder = proc_open('timeout --kill-after=1s 5s flock -x '.escapeshellarg($path).' sh -c '.escapeshellarg('echo ready; sleep 30'),
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $this->assertTrue(is_resource($holder));
        try {
            $this->assertSame("ready\n", fgets($pipes[1]));
            $start = microtime(true);
            $this->pmssWithEnv(['PMSS_TEST_MODE' => '1', 'PMSS_RUNTIME_DIR' => $this->tempDir,
                'PMSS_WATCHDOG_TIMEOUT_TEST_SECONDS' => '1'], function () use ($home, $path): void {
                $owner = $this->pmssCurrentOwner();
                if (!\pmssValidateUsername($owner)) $owner = 'pmsslog';
                $this->assertFalse(\pmssTrackerCleanerAccountLogAppend($owner, $home, $path, 'new'));
                $this->assertTrue(\pmssUserWatchdogBackoffActive($owner, 'tracker-cleaner'));
                $this->assertFalse(\pmssTrackerCleanerAccountLogAppend($owner, $home, $path, 'new'));
            });
            $this->assertTrue(microtime(true) - $start < 1 + PMSS_COMMAND_TIMEOUT_BACKSTOP_GRACE_SECONDS + 3);
            $this->assertSame('old', file_get_contents($path));
        } finally {
            fclose($pipes[1]);
            proc_terminate($holder);
            proc_close($holder);
        }
    }

    public function testAccountAppendRejectsUnsafeTargets(): void
    {
        $home = $this->tempDir.'/account';
        $this->assertTrue(@mkdir($home));
        $this->assertTrue(@file_put_contents($home.'/original', 'kept') !== false);
        $this->assertTrue(@symlink($home.'/original', $home.'/linked'));
        $owner = $this->pmssCurrentOwner();

        $this->assertFalse(\pmssAppendAccountFile($owner, $home, $home.'/linked', 'new', 0640));
        $this->assertFalse(\pmssAppendAccountFile($owner, $home, $this->tempDir.'/outside', 'new', 0640));
        $this->assertFalse(\pmssAppendAccountFile($owner, $home, $home.'/missing/file', 'new', 0640));
        $this->assertFalse(\pmssAppendAccountFile($owner, $home, $home.'/file', 'new', 01000));
        $this->assertFalse(\pmssAppendAccountFile('pmss-no-such-account-123', $home, $home.'/file', 'new', 0640));
        $this->assertSame('kept', file_get_contents($home.'/original'));
    }

    public function testWriteUserFileRejectsSymlinkedParentDirectory(): void
    {
        [, $linkDir] = $this->pmssCreateSymlinkedDirectoryOrSkip($this->tempDir.'/real', $this->tempDir.'/linked');

        $this->assertFalse(\pmssWriteUserFile($linkDir.'/.htpasswd', "user:hash\n", $this->pmssCurrentOwner(), 0640));
    }

    public function testWriteUserFileRejectsSymlinkedTarget(): void
    {
        [$realPath, $linkPath] = $this->pmssCreateSymlinkedFileOrSkip(
            $this->tempDir.'/other-file',
            $this->tempDir.'/.request-web-certs.failed',
            "original\n"
        );

        $this->assertFalse(\pmssWriteUserFile($linkPath, "replacement\n", $this->pmssCurrentOwner(), 0644));
        $this->assertSame("original\n", file_get_contents($realPath));
    }

    public function testWriteUserFileRejectsRelativePath(): void
    {
        $this->assertFalse(\pmssWriteUserFile('relative.htpasswd', "user:hash\n", $this->pmssCurrentOwner(), 0640));
        $this->assertFalse(file_exists('relative.htpasswd'));
    }

    public function testWriteUserFileRejectsTraversalSegment(): void
    {
        $managedDir = $this->tempDir.'/user/.lighttpd';
        $outsideDir = $this->tempDir.'/user/outside';
        @mkdir($managedDir, 0755, true);
        @mkdir($outsideDir, 0755, true);

        $path = $managedDir.'/../outside/.htpasswd';
        $this->assertFalse(\pmssWriteUserFile($path, "user:hash\n", $this->pmssCurrentOwner(), 0640));
        $this->assertFalse(file_exists($outsideDir.'/.htpasswd'));
    }

    public function testJsonFileReadAssocRejectsTraversalWhenSafePathRequired(): void
    {
        $managedDir = $this->tempDir.'/user/.lighttpd';
        $outsideDir = $this->tempDir.'/user/outside';
        @mkdir($managedDir, 0755, true);
        @mkdir($outsideDir, 0755, true);

        $outsidePath = $outsideDir.'/state.json';
        file_put_contents($outsidePath, json_encode(['ok' => true]));

        $this->assertEquals(null, \pmssJsonFileReadAssoc($managedDir.'/../outside/state.json', true));
    }

    public function testReplaceUserFileCleansTempFileWhenPrepareTempThrows(): void
    {
        $path = $this->tempDir.'/user/.lighttpd/.htpasswd';
        @mkdir(dirname($path), 0755, true);

        $before = glob(dirname($path).'/.htpasswd.pmss-tmp-*');
        $before = is_array($before) ? $before : [];

        $this->assertThrowsRuntime(static function () use ($path): void {
            \pmssReplaceUserFile($path, "user:hash\n", static function (): void {
                throw new \RuntimeException('metadata failure');
            });
        }, 'metadata failure');

        $after = glob(dirname($path).'/.htpasswd.pmss-tmp-*');
        $after = is_array($after) ? $after : [];

        $this->assertEquals($before, $after);
        $this->assertFalse(file_exists($path));
    }

    public function testReplaceUserFileRejectsPrepareTempSymlinkSwap(): void
    {
        $path = $this->tempDir.'/user/.lighttpd/.htpasswd';
        $outsidePath = $this->stagingSwapFixture($path);

        $this->assertFalse(\pmssReplaceUserFile($path, "user:hash\n", function (string $tmp) use ($path, $outsidePath): void {
            $this->swapStagedFileForLink($path, $outsidePath);
        }));

        $this->assertStagingSwapLeftOutsideUntouched($path, $outsidePath);
    }

    public function testReplaceUserFilePrepareTempMetadataStaysOnStagedInodeAfterSwap(): void
    {
        $path = $this->tempDir.'/user/.lighttpd/.htpasswd';
        $outsidePath = $this->stagingSwapFixture($path);

        $this->assertFalse(\pmssReplaceUserFile($path, "user:hash\n", function (string $tmp) use ($path, $outsidePath): void {
            $this->swapStagedFileForLink($path, $outsidePath);
            // Callers chmod the path they are given; it must not reach the linked file.
            chmod($tmp, 0600);
            \pmssUserFileApplyMetadata($tmp, $this->pmssCurrentOwner(), 0600);
        }));

        $this->assertStagingSwapLeftOutsideUntouched($path, $outsidePath);
    }

    public function testReplaceUserFileGivesPrepareTempTheOpenedStagingFile(): void
    {
        $path = $this->tempDir.'/user/.lighttpd/.htpasswd';
        @mkdir(dirname($path), 0755, true);
        $seen = [];

        $this->assertTrue(\pmssReplaceUserFile($path, "user:hash\n", static function (string $tmp) use (&$seen): bool {
            $seen = [$tmp, (string) file_get_contents($tmp)];
            return chmod($tmp, 0640);
        }));

        $this->assertTrue(\pmssUserFileOpenedPathIs($seen[0]));
        $this->assertSame("user:hash\n", $seen[1]);
        clearstatcache(true, $path);
        $this->assertSame("user:hash\n", file_get_contents($path));
        $this->assertSame(0640, fileperms($path) & 0777);
        $this->assertSame([], glob(dirname($path).'/.htpasswd.pmss-tmp-*'));
    }

    public function testReplaceUserFileStagesPrivatelyWithoutPrepareTemp(): void
    {
        $path = $this->tempDir.'/user/'.str_repeat('n', 200);
        @mkdir(dirname($path), 0755, true);

        $this->assertTrue(\pmssReplaceUserFile($path, 'payload'));
        $this->assertSame('payload', file_get_contents($path));
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame([$path], glob(dirname($path).'/*'));
    }

    public function testReplaceUserFileRejectsRegularStagingReplacementAndExtraLink(): void
    {
        $path = $this->tempDir.'/managed';
        $this->pmssWriteFile($path, 'original');
        foreach (['replacement', 'hardlink'] as $kind) {
            $this->assertFalse(\pmssReplaceUserFile($path, 'new', function (string $opened) use ($path, $kind): void {
                $staged = glob($path.'.pmss-tmp-*');
                $this->assertSame(1, count($staged));
                if ($kind === 'replacement') {
                    $this->assertTrue(unlink($staged[0]));
                    $this->pmssWriteFile($staged[0], 'substitute');
                } else {
                    $this->assertTrue(link($staged[0], $path.'.extra'));
                }
            }));
            $this->assertSame('original', file_get_contents($path));
            $this->assertSame([], glob($path.'.pmss-tmp-*'));
        }
    }

    public function testExclusiveStagingRetriesCollisionsAndRestoresUmask(): void
    {
        foreach ([0, 1, 3, 4, 'entropy'] as $collisions) {
            $root = $this->pmssEnsureDir($this->tempDir.'/collisions-'.$collisions);
            $script = <<<'PHP'
namespace StagingCollisionFixture;
function random_bytes($length) {
    if ($GLOBALS['collisions'] === 'entropy') throw new \RuntimeException('unavailable');
    return str_repeat(chr(++$GLOBALS['attempts']), $length);
}
PHP;
            $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/lighttpd/userFileWrite.php', 'StagingCollisionFixture');
            $script .= '$root = '.var_export($root, true).'; $GLOBALS["collisions"] = '.var_export($collisions, true).';';
            $script .= <<<'PHP'
$GLOBALS['attempts'] = 0;
$path = $root.'/managed';
$kept = [];
for ($i = 1; is_int($GLOBALS['collisions']) && $i <= $GLOBALS['collisions']; $i++) {
    $name = $path.'.pmss-tmp-'.bin2hex(str_repeat(chr($i), 6));
    \file_put_contents($name, 'existing');
    $kept[] = $name;
}
$prior = umask(0000);
$permissions = null;
try {
    $ok = pmssReplaceUserFile($path, 'payload', static function ($opened) use (&$permissions) {
        $permissions = fileperms($opened) & 0777;
    });
    $restored = umask();
} finally { umask($prior); }
$contents = array_map('file_get_contents', $kept);
echo json_encode(['ok' => $ok, 'attempts' => $GLOBALS['attempts'], 'mode' => $permissions,
    'umask' => $restored, 'kept' => $contents, 'temporary' => glob($path.'.pmss-tmp-*')]);
PHP;
            $result = $this->pmssRunInlinePhpJson($script);
            $success = is_int($collisions) && $collisions < 4;
            $this->assertSame($success, $result['ok']);
            $this->assertSame($collisions === 'entropy' ? 0 : min(4, $collisions + 1), $result['attempts']);
            $this->assertSame($success ? 0600 : null, $result['mode']);
            $this->assertSame(0, $result['umask']);
            $this->assertSame(array_fill(0, (int) $collisions, 'existing'), $result['kept']);
            $this->assertSame((int) $collisions, count($result['temporary']));
        }
    }

    /** Create the managed directory and an outside file the account could aim a link at. */
    private function stagingSwapFixture(string $path): string
    {
        $outsidePath = $this->tempDir.'/outside.htpasswd';
        @mkdir(dirname($path), 0755, true);
        $this->pmssWriteFile($outsidePath, "outside:hash\n");
        $this->assertTrue(chmod($outsidePath, 0644));
        return $outsidePath;
    }

    /** Replace the real staging name with a link, as an account owning the directory can. */
    private function swapStagedFileForLink(string $path, string $target): void
    {
        $staged = glob(dirname($path).'/'.basename($path).'.pmss-tmp-*') ?: [];
        $this->assertSame(1, count($staged));
        $this->assertTrue(@unlink($staged[0]) && @symlink($target, $staged[0]));
    }

    private function assertStagingSwapLeftOutsideUntouched(string $path, string $outsidePath): void
    {
        clearstatcache();
        $this->assertFalse(file_exists($path) || is_link($path));
        $this->assertSame("outside:hash\n", file_get_contents($outsidePath));
        $this->assertSame(0644, fileperms($outsidePath) & 0777);
        $this->assertSame([], glob(dirname($path).'/'.basename($path).'.pmss-tmp-*'));
    }

    public function testReplaceUserFileRejectsParentDirectorySwapBeforeRename(): void
    {
        $managedDir = $this->tempDir.'/user/.lighttpd';
        $movedDir = $this->tempDir.'/user/.lighttpd-real';
        $path = $managedDir.'/.htpasswd';
        @mkdir($managedDir, 0755, true);
        $this->pmssWriteFile($path, "original:hash\n");

        $this->assertFalse(\pmssReplaceUserFile($path, "user:hash\n", static function (string $tmp) use ($managedDir, $movedDir): void {
            rename($managedDir, $movedDir);
            symlink($movedDir, $managedDir);
            clearstatcache(true, $tmp);
        }));

        $this->assertTrue(is_link($managedDir));
        $this->assertEquals("original:hash\n", file_get_contents($movedDir.'/.htpasswd'));
        $this->assertEquals(0, count(glob($movedDir.'/.htpasswd.pmss-tmp-*') ?: []));
    }

    public function testReplacementMetadataFailurePreservesExistingFile(): void
    {
        $path = $this->tempDir.'/managed';
        $this->pmssWriteFile($path, 'original');
        $this->assertTrue(chmod($path, 0600));

        foreach ([-1, 010000] as $mode) {
            $this->assertFalse(\pmssReplaceUserFileWithMetadata($path, 'new', $mode));
            $this->assertFalse(\pmssReplaceUserFilePreservingMetadata($this->tempDir.'/fresh', 'new', $mode));
            clearstatcache(true, $path);
            $this->assertSame('original', file_get_contents($path));
            $this->assertSame(0600, fileperms($path) & 0777);
            $this->assertFalse(file_exists($this->tempDir.'/fresh'));
            $this->assertSame([], glob($this->tempDir.'/*.pmss-tmp-*'));
        }
    }

    public function testReplacementMetadataSuccessKeepsModeAndContent(): void
    {
        $path = $this->tempDir.'/managed';
        $this->assertTrue(\pmssReplaceUserFileWithMetadata($path, 'first', 0640, $this->pmssCurrentOwner()));
        clearstatcache(true, $path);
        $this->assertSame(0640, fileperms($path) & 0777);
        $this->assertTrue(\pmssReplaceUserFilePreservingMetadata($path, 'second'));
        clearstatcache(true, $path);
        $this->assertSame('second', file_get_contents($path));
        $this->assertSame(0640, fileperms($path) & 0777);
    }

    public function testCheckUserHtpasswdUsesSafeAppendHelper(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'scripts/util/checkUserHtpasswd.php',
            ['pmssAppendAccountFile(', 'pmssCheckUserHtpasswdLegacyAppend(', 'Unable to append legacy credential to per-user htpasswd'],
            ['file_put_contents($userHtpasswd']
        );
    }

    public function testReplaceRejectsShortWritesBeforePreparingOrPublishing(): void
    {
        foreach ([null, 'original'] as $existing) {
            foreach ([0, 1, 7] as $limit) {
                $result = $this->writeWithFileSizeLimit(false, $existing, 'complete', $limit);
                $this->assertFalse($result['ok']);
                $this->assertSame($existing, $result['content']);
                $this->assertFalse($result['prepared']);
                $this->assertSame([], $result['temporary']);
                $this->assertSame($existing === null ? null : 0600, $result['mode']);
            }
        }
    }

    public function testHandleWriterDoesNotRetryAShortWrite(): void
    {
        $script = <<<'PHP'
namespace HandleWriteFixture;
function fwrite($handle, $bytes) {
    // A second call would succeed, so retrying would hide the initial short write.
    $bytes = ++$GLOBALS['calls'] === 1 ? substr($bytes, 0, $GLOBALS['limit']) : $bytes;
    return \fwrite($handle, $bytes);
}
PHP;
        $script .= $this->pmssInlinePhpLibraryInNamespace('scripts/lib/lighttpd/userFileWrite.php', 'HandleWriteFixture');
        $script .= <<<'PHP'
$results = [];
foreach ([0, 1, 3, 7, 8] as $limit) {
    $GLOBALS['calls'] = 0;
    $GLOBALS['limit'] = $limit;
    $handle = \fopen('php://temp', 'w+b');
    try {
        $ok = pmssUserFileHandleWriteComplete($handle, 'complete');
        rewind($handle);
        $results[] = [$ok, $GLOBALS['calls'], stream_get_contents($handle)];
    } finally { fclose($handle); }
}
echo json_encode($results);
PHP;
        foreach ($this->pmssRunInlinePhpJson($script) as $index => $result) {
            $limit = [0, 1, 3, 7, 8][$index];
            $this->assertSame([$limit === 8, 1, substr('complete', 0, $limit)], $result);
        }
    }

    public function testAppendReportsShortWritesWithoutChangingMetadata(): void
    {
        foreach ([0, 1, 7] as $bytes) {
            $result = $this->writeWithFileSizeLimit(true, 'original', 'complete', 8 + $bytes);
            $this->assertFalse($result['ok']);
            $this->assertSame('original'.substr('complete', 0, $bytes), $result['content']);
            $this->assertSame(0600, $result['mode']);
            $this->assertSame([], $result['temporary']);
        }
    }

    public function testCompleteWritesRetainEmptyAndBinaryPayloadContracts(): void
    {
        foreach ([false, true] as $append) {
            foreach ([null, 'original'] as $existing) {
                foreach (['', 'complete', "binary\0\xff\n"] as $payload) {
                    $expected = ($append ? (string) $existing : '').$payload;
                    $result = $this->writeWithFileSizeLimit($append, $existing, $payload, strlen($expected));
                    $this->assertTrue($result['ok']);
                    $this->assertSame(base64_encode($expected), $result['encoded']);
                    $this->assertSame(!$append, $result['prepared']);
                    $this->assertSame(0640, $result['mode']);
                    $this->assertSame([], $result['temporary']);
                }
            }
        }
    }

    /**
     * Limit writes only in a child process; all files remain in test fixtures.
     *
     * The payload crosses into the child base64-encoded on purpose. pmssRunInlinePhp()
     * ships the script through escapeshellarg(), and PHP strips bytes that are invalid
     * in the current LC_CTYPE encoding -- under C.UTF-8 escapeshellarg("\xff") is "''".
     * A var_export()ed raw binary payload therefore arrives truncated and the binary
     * contract below silently tests nothing.
     */
    private function writeWithFileSizeLimit(bool $append, ?string $existing, string $payload, int $limit): array
    {
        if (!function_exists('posix_setrlimit') || !function_exists('pcntl_signal')) {
            throw new SkipTest('File-size fault injection requires POSIX and PCNTL');
        }
        $directory = $this->pmssMakeTempDir('pmss-user-write-limit-', 0700);
        $path = $directory.'/snapshot';
        if ($existing !== null) {
            $this->pmssWriteFile($path, $existing);
            chmod($path, 0600);
        }
        $script = 'require '.var_export(dirname(__DIR__, 2).'/lighttpd/userFileWrite.php', true).';'
            .'$path = '.var_export($path, true).'; $payload = base64_decode('.var_export(base64_encode($payload), true).'); $prepared = false;'
            .'if (!pcntl_signal(SIGXFSZ, SIG_IGN) || !posix_setrlimit(POSIX_RLIMIT_FSIZE, '.$limit.', '.$limit.')) { exit(2); }'
            .'$ok = '.($append
                ? 'pmssAppendUserFile($path, $payload, '.var_export($this->pmssCurrentOwner(), true).', 0640);'
                : 'pmssReplaceUserFile($path, $payload, static function ($tmp) use (&$prepared): void { $prepared = true; chmod($tmp, 0640); });')
            .'clearstatcache(); $content = is_file($path) ? file_get_contents($path) : null;'
            .'echo json_encode(["ok" => $ok, "encoded" => $content === null ? null : base64_encode($content),'
            .'"mode" => is_file($path) ? fileperms($path) & 0777 : null, "prepared" => $prepared,'
            .'"temporary" => glob(dirname($path)."/snapshot.pmss-tmp-*")]);';
        $result = $this->pmssRunInlinePhpJson($script);
        $result['content'] = $result['encoded'] === null ? null : base64_decode($result['encoded']);
        return $result;
    }
}
