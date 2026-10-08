<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once __DIR__.'/../../user/recreateRestore.php';

class RecreateUserSafetyGuardTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array
    {
        return ['tempDir', 'recreate-restore', 0700, getenv('PMSS_TEST_TEMP_ROOT') ?: sys_get_temp_dir()];
    }

    private function fixture(string $name): array
    {
        $base = $this->tempDir.'/'.$name;
        $this->pmssEnsureDir($base.'/home');
        $this->pmssEnsureDir($base.'/backup');
        $this->assertTrue(chmod($base.'/home', 0700));
        $this->assertTrue(chmod($base.'/backup', 0700));
        return [$base.'/home', $base.'/backup', $base];
    }

    private function restore(string $home, string $backup, bool $hadHome = true, ?int $identityUid = null): array
    {
        $uid = posix_geteuid();
        return \pmssRecreateRestoreHome($home, $backup, $hadHome, $uid, posix_getegid(), $identityUid ?? $uid, $uid);
    }

    public function testWritablePreflightLeavesNoProbeFiles(): void
    {
        $directories = [$this->tempDir.'/write-home', $this->tempDir.'/write-runtime', $this->tempDir.'/write-etc'];
        foreach ($directories as $directory) {
            $this->pmssEnsureDir($directory);
        }
        $beforeUmask = umask();

        \pmssRecreateRequireWritableDirectories($directories);

        $this->assertSame($beforeUmask, umask());
        foreach ($directories as $directory) {
            $this->assertSame(['.', '..'], scandir($directory));
        }
    }

    public function testWritablePreflightRefusesUnwritableDirectoryWithoutResidue(): void
    {
        if (posix_geteuid() === 0) {
            throw new SkipTest('Root can write through mode 0500; chmod cannot simulate a read-only mount');
        }
        $directories = [$this->tempDir.'/refuse-home', $this->tempDir.'/refuse-runtime', $this->tempDir.'/refuse-etc'];
        foreach ($directories as $directory) {
            $this->pmssEnsureDir($directory);
        }
        $this->assertTrue(chmod($directories[1], 0500));
        try {
            $this->assertThrowsRuntime(function () use ($directories): void {
                \pmssRecreateRequireWritableDirectories($directories);
            }, $directories[1]);
            foreach ($directories as $directory) {
                $this->assertSame(['.', '..'], scandir($directory));
            }
        } finally {
            chmod($directories[1], 0700);
        }
    }

    public function testWritablePreflightPrecedesBackupAndProcessChanges(): void
    {
        $script = $this->pmssReadRepoFile('scripts/recreateUser.php');
        $this->assertOrderedStrings([
            'pmssRequireSafeRecreateUserPath($backupDir',
            'pmssRecreateRequireWritableDirectories(',
            'Setting aside prior backup',
            'Killing processes for',
            'pmssRecreateMoveTopDirectory($homeDir, $backupDir)',
        ], $script);
    }

    public function testTopLevelRecreateMoveKeepsEveryByteAndOriginalInode(): void
    {
        $base = $this->tempDir.'/top-real';
        $this->pmssWriteFile($base.'/home/data/movie', "movie\0bytes");
        $this->pmssWriteFile($base.'/home/session/lock', 'session');
        $before = lstat($base.'/home');
        \pmssRecreateMoveTopDirectory($base.'/home', $base.'/backup');
        $this->assertFalse(file_exists($base.'/home'));
        $this->assertSame("movie\0bytes", file_get_contents($base.'/backup/data/movie'));
        $this->assertSame('session', file_get_contents($base.'/backup/session/lock'));
        $this->assertSame($before['ino'], lstat($base.'/backup')['ino']);
    }

    public function testTopLevelRecreateMoveRefusesLinkedPathsAndPreservesBothTrees(): void
    {
        $base = $this->tempDir.'/top-linked';
        $this->pmssWriteFile($base.'/outside/payload', 'outside');
        $this->pmssWriteFile($base.'/home/data/payload', 'tenant');
        symlink($base.'/outside', $base.'/backup');
        $this->assertThrowsRuntime(static function () use ($base): void {
            \pmssRecreateMoveTopDirectory($base.'/home', $base.'/backup');
        });
        $this->assertSame('tenant', file_get_contents($base.'/home/data/payload'));
        $this->assertSame('outside', file_get_contents($base.'/outside/payload'));
        unlink($base.'/backup');
        rename($base.'/home', $base.'/saved-home');
        symlink($base.'/outside', $base.'/home');
        $this->assertThrowsRuntime(static function () use ($base): void {
            \pmssRecreateMoveTopDirectory($base.'/home', $base.'/backup');
        });
        $this->assertSame('tenant', file_get_contents($base.'/saved-home/data/payload'));
        $this->assertSame('outside', file_get_contents($base.'/outside/payload'));
    }

    public function testRecreateSupersededPurgeRemovesRealTreeAndRefusesLinkedRoot(): void
    {
        $base = $this->tempDir.'/superseded';
        $this->pmssWriteFile($base.'/archive/nested/payload', 'customer');
        $this->assertTrue(\pmssRecreatePurgeSupersededDirectory($base.'/archive'));
        $this->assertFalse(file_exists($base.'/archive'));
        $this->pmssWriteFile($base.'/outside/payload', 'outside');
        $this->pmssWriteFile($base.'/saved/payload', 'tenant');
        symlink($base.'/outside', $base.'/archive');
        $this->assertFalse(\pmssRecreatePurgeSupersededDirectory($base.'/archive'));
        $this->assertSame('outside', file_get_contents($base.'/outside/payload'));
        $this->assertSame('tenant', file_get_contents($base.'/saved/payload'));
    }

    public function testRestoreMovesCustomerDirectoriesWithoutDoublingData(): void
    {
        [$home, $backup] = $this->fixture('complete');
        $this->pmssEnsureDir($home.'/data');
        $this->pmssWriteFile($backup.'/data/movie', 'movie');
        $this->pmssWriteFile($backup.'/session/lock', 'session');
        $this->pmssWriteFile($backup.'/.local/share/pmss/public/index', 'public');
        $this->pmssWriteFile($backup.'/.local/share/pmss/rutorrent/share/state', 'share');
        $this->pmssWriteFile($backup.'/.config/pmss/override', 'override');
        $this->pmssWriteFile($backup.'/.rtorrent.rc.custom', 'custom');
        $dataInode = fileinode($backup.'/data');
        $shareInode = fileinode($backup.'/.local/share/pmss');

        $left = $this->restore($home, $backup);

        $this->assertSame($dataInode, fileinode($home.'/data'));
        $this->assertSame($shareInode, fileinode($home.'/.local/share/pmss'));
        foreach (['data', 'session', '.local/share/pmss'] as $relative) {
            $this->assertFalse(file_exists($backup.'/'.$relative));
        }
        $this->assertSame('movie', file_get_contents($home.'/data/movie'));
        $this->assertSame('session', file_get_contents($home.'/session/lock'));
        $this->assertSame('public', file_get_contents($home.'/.local/share/pmss/public/index'));
        $this->assertSame('share', file_get_contents($home.'/.local/share/pmss/rutorrent/share/state'));
        $this->assertSame([$backup.'/.config/pmss', $backup.'/.rtorrent.rc.custom'], $left);
        $this->assertFalse(file_exists($home.'/.config/pmss'));
        $this->assertSame('override', file_get_contents($backup.'/.config/pmss/override'));
    }

    public function testRestoreCopyRefusesSymlinkedFinalDestination(): void
    {
        [$home, $backup, $base] = $this->fixture('copy-final-link');
        $source = $backup.'/.billingServiceId';
        $outside = $base.'/outside';
        $this->pmssWriteFile($source, "123\n");
        $this->pmssWriteFile($outside, 'keep');
        $this->assertTrue(symlink($outside, $home.'/.billingServiceId'));

        $this->assertThrowsRuntime(static function () use ($source, $home): void {
            \pmssRecreateCopyFile($source, $home.'/.billingServiceId');
        }, 'Unsafe restore file');
        $this->assertSame('keep', file_get_contents($outside));
        $this->assertSame("123\n", file_get_contents($source));
    }

    public function testRestoreCopyPreservesRealFileBytes(): void
    {
        [$home, $backup] = $this->fixture('copy-real-file');
        $source = $backup.'/.billingServiceId';
        $destination = $home.'/.billingServiceId';
        $this->pmssWriteFile($source, "123\n");

        $this->assertTrue(\pmssRecreateCopyFile($source, $destination));
        $this->assertSame("123\n", file_get_contents($destination));
        $this->assertSame("123\n", file_get_contents($source));
    }

    public function testRepositorySkeletonAllowsRestoreOfExistingData(): void
    {
        [$home, $backup] = $this->fixture('repository-skeleton');
        $skel = dirname(__DIR__, 4).'/etc/skel';
        $copyRc = 1;
        exec('cp -Rp '.escapeshellarg($skel.'/.').' '.escapeshellarg($home), $copyOutput, $copyRc);
        $this->assertSame(0, $copyRc, 'Unable to copy repository skeleton into test home');
        $this->assertTrue(is_file($home.'/data/.gitkeep'), 'Repository skeleton must exercise the data placeholder');
        $this->assertTrue(chmod($home, 0700));

        $this->pmssWriteFile($backup.'/data/movie', 'movie');
        $this->pmssWriteFile($backup.'/session/lock', 'session');
        $this->pmssWriteFile($backup.'/.local/share/pmss/public/index', 'public');
        $dataInode = fileinode($backup.'/data');

        $this->restore($home, $backup);

        $this->assertSame($dataInode, fileinode($home.'/data'));
        $this->assertSame('movie', file_get_contents($home.'/data/movie'));
        $this->assertSame('session', file_get_contents($home.'/session/lock'));
        $this->assertSame('public', file_get_contents($home.'/.local/share/pmss/public/index'));
        $this->assertFalse(file_exists($home.'/data/.gitkeep'));
        $this->assertFalse(file_exists($backup.'/data'));
        $this->assertFalse(file_exists($backup.'/session'));
        $this->assertFalse(file_exists($backup.'/.local/share/pmss'));
    }

    public function testBillingAndCredentialCopyPreservesIdentityMetadata(): void
    {
        [$home, $backup] = $this->fixture('identity');
        $names = ['.billingServiceId', '.billingId', '.billingClientId', '.notifyEmail'];
        foreach ($names as $name) {
            $this->pmssWriteFile($backup.'/'.$name, $name);
        }
        $this->pmssWriteFile($backup.'/.lighttpd/.htpasswd', 'hash');

        $this->restore($home, $backup);

        foreach ($names as $name) {
            $this->assertSame($name, file_get_contents($home.'/'.$name));
            $this->assertSame(posix_geteuid(), fileowner($home.'/'.$name));
            $this->assertSame(posix_getegid(), filegroup($home.'/'.$name));
            $this->assertSame(0640, fileperms($home.'/'.$name) & 0777);
        }
        $this->assertSame('hash', file_get_contents($home.'/.lighttpd/.htpasswd'));
    }

    public function testLinkedRestoreDestinationsLeaveTargetsUntouched(): void
    {
        $locations = ['data', 'session', '.local/share/pmss', '.lighttpd/.htpasswd',
            '.billingServiceId', '.billingId', '.billingClientId', '.notifyEmail'];
        foreach ($locations as $index => $relative) {
            [$home, $backup, $base] = $this->fixture('link-'.$index);
            $target = $base.'/outside';
            $directory = in_array($relative, ['data', 'session', '.local/share/pmss'], true);
            $this->pmssWriteFile($directory ? $target.'/sentinel' : $target, 'untouched');
            $this->pmssWriteFile($backup.'/'.$relative.($directory ? '/payload' : ''), 'payload');
            $this->pmssEnsureDir(dirname($home.'/'.$relative));
            $this->assertTrue(symlink($target, $home.'/'.$relative));

            $this->assertThrowsRuntime(function () use ($home, $backup): void {
                $this->restore($home, $backup);
            });

            $this->assertTrue(is_link($home.'/'.$relative));
            $this->assertSame('untouched', file_get_contents($directory ? $target.'/sentinel' : $target));
            $this->assertTrue(file_exists($backup.'/'.$relative));
        }
    }

    public function testLinkedParentsAndSourcesAreRejected(): void
    {
        [$home, $backup, $base] = $this->fixture('parent');
        $this->pmssWriteFile($base.'/outside/sentinel', 'untouched');
        $this->assertTrue(symlink($base.'/outside', $home.'/.local'));
        $this->pmssWriteFile($backup.'/.local/share/pmss/file', 'original');
        $this->assertThrowsRuntime(function () use ($home, $backup): void {
            $this->restore($home, $backup);
        });
        $this->assertSame('untouched', file_get_contents($base.'/outside/sentinel'));

        [$home2, $backup2] = $this->fixture('source');
        $this->assertTrue(symlink($base.'/outside', $backup2.'/data'));
        $this->assertThrowsRuntime(function () use ($home2, $backup2): void {
            $this->restore($home2, $backup2);
        });
    }

    public function testOccupiedDestinationPreservesBothTrees(): void
    {
        [$home, $backup] = $this->fixture('occupied');
        $this->pmssWriteFile($home.'/data/existing', 'existing');
        $this->pmssWriteFile($backup.'/data/new', 'new');
        $this->assertThrowsRuntime(function () use ($home, $backup): void {
            $this->restore($home, $backup);
        });
        $this->assertSame('existing', file_get_contents($home.'/data/existing'));
        $this->assertSame('new', file_get_contents($backup.'/data/new'));
    }

    public function testPlaceholderMustBeARegularFileAndOnlyEntry(): void
    {
        foreach (['link', 'directory', 'extra-file'] as $kind) {
            [$home, $backup, $base] = $this->fixture('placeholder-'.$kind);
            $this->pmssEnsureDir($home.'/data');
            $this->pmssWriteFile($backup.'/data/new', 'new');
            if ($kind === 'link') {
                $this->pmssWriteFile($base.'/outside', 'untouched');
                $this->assertTrue(symlink($base.'/outside', $home.'/data/.gitkeep'));
            } elseif ($kind === 'directory') {
                $this->pmssEnsureDir($home.'/data/.gitkeep');
            } else {
                $this->pmssWriteFile($home.'/data/.gitkeep', 'placeholder');
                $this->pmssWriteFile($home.'/data/existing', 'existing');
            }

            $this->assertThrowsRuntime(function () use ($home, $backup): void {
                $this->restore($home, $backup);
            });
            $this->assertSame('new', file_get_contents($backup.'/data/new'));
            $this->assertTrue(file_exists($home.'/data/.gitkeep') || is_link($home.'/data/.gitkeep'));
            if ($kind === 'link') {
                $this->assertSame('untouched', file_get_contents($base.'/outside'));
            }
        }
    }

    public function testRestoreRefusesAccessibleHomeOrArchive(): void
    {
        [$home, $backup] = $this->fixture('accessible');
        $this->pmssWriteFile($backup.'/data/sole-copy', 'keep');
        $this->assertTrue(chmod($home, 0755));
        $this->assertThrowsRuntime(function () use ($home, $backup): void {
            $this->restore($home, $backup);
        });
        $this->assertTrue(chmod($home, 0700));
        $this->assertTrue(chmod($backup, 0755));
        $this->assertThrowsRuntime(function () use ($home, $backup): void {
            $this->restore($home, $backup);
        });
        $this->assertSame('keep', file_get_contents($backup.'/data/sole-copy'));
    }

    public function testPrivateDirectoryRechecksModeAfterShellChmod(): void
    {
        [$home] = $this->fixture('shell-chmod');
        $this->assertTrue(chmod($home, 0770));
        $this->assertFalse(is_link($home));
        $stat = lstat($home);
        $this->assertTrue(is_array($stat));
        $this->assertSame(0770, $stat['mode'] & 0777);

        // Shell changes do not invalidate PHP's cached lstat for this path.
        exec('chmod 0700 '.escapeshellarg($home), $output, $rc);
        $this->assertSame(0, $rc);
        \pmssRecreateRequirePrivateDirectory($home, $stat['uid']);
    }

    public function testInvalidBillingSourceIsLeftBehindWithoutAborting(): void
    {
        [$home, $backup, $base] = $this->fixture('bad-identity');
        $this->pmssWriteFile($backup.'/.billingServiceId', 'value');
        $this->assertSame([$backup.'/.billingServiceId'], $this->restore($home, $backup, true, posix_geteuid() + 1));
        $this->assertFalse(file_exists($home.'/.billingServiceId'));

        $this->assertTrue(unlink($backup.'/.billingServiceId'));
        $this->pmssWriteFile($base.'/outside', 'untouched');
        $this->assertTrue(symlink($base.'/outside', $backup.'/.billingServiceId'));
        $this->assertSame([$backup.'/.billingServiceId'], $this->restore($home, $backup));
        $this->assertFalse(file_exists($home.'/.billingServiceId'));
        $this->assertTrue(is_link($backup.'/.billingServiceId'));
        $this->assertSame('untouched', file_get_contents($base.'/outside'));
    }

    public function testPasswordCredentialPathRejectsLinksAfterConfiguration(): void
    {
        [$home, , $base] = $this->fixture('password');
        $credential = $home.'/.lighttpd/.htpasswd';
        $this->pmssEnsureDir(dirname($credential));
        $this->assertTrue(\pmssRecreateCredentialPathIsSafe($credential));
        $this->pmssWriteFile($credential, 'hash');
        $this->assertTrue(\pmssRecreateCredentialPathIsSafe($credential));
        $this->assertTrue(unlink($credential));
        $this->pmssWriteFile($base.'/outside', 'untouched');
        $this->assertTrue(symlink($base.'/outside', $credential));
        $this->assertFalse(\pmssRecreateCredentialPathIsSafe($credential));
        $this->assertSame('untouched', file_get_contents($base.'/outside'));
    }

    public function testBackupHandoffWaitsForSuccessfulPasswordAndRefusesLinks(): void
    {
        $script = $this->pmssReadRepoFile('scripts/recreateUser.php');
        $handoff = substr($script, strpos($script, 'if ($passwordRc !== 0)'));
        $this->assertOrderedStrings([
            'if ($passwordRc !== 0)',
            "pmssRequireSafeRecreateUserPath(\$backupDir, 'backup');",
            "pmssRunOrExit('chown -h ' . escapeshellarg(\$userName.':'.\$userName)",
            "pmssRunOrExit('chmod 0700 ' . escapeshellarg(\$backupDir));",
            '/* ===== 11. Reclaim the superseded prior backup',
        ], $handoff);
    }

    public function testEnvironmentPassFollowsPasswordAndBackupHandoffWithoutAborting(): void
    {
        $script = $this->pmssReadRepoFile('scripts/recreateUser.php');
        $environmentCall = "pmssUpdateUserEnvironment(\$userName, '', \$environmentReason)";
        $reclaimHeading = '/* ===== 11. Reclaim the superseded prior backup';
        $this->assertOrderedStrings([
            '/changePw.php ',
            "pmssRunOrExit('chown -h ' . escapeshellarg(\$userName.':'.\$userName)",
            $environmentCall,
            $reclaimHeading,
        ], $script);
        $environmentPosition = strpos($script, $environmentCall);
        $reclaimPosition = strpos($script, $reclaimHeading, $environmentPosition);
        $this->assertFalse(strpos(substr($script, $environmentPosition, $reclaimPosition - $environmentPosition), 'exit(') !== false);
    }

    public function testMissingHomeLeavesPossibleSoleCopyInBackup(): void
    {
        [$home, $backup] = $this->fixture('fresh');
        $this->pmssWriteFile($backup.'/data/sole-copy', 'keep');
        $this->assertTrue(chmod($backup, 0755));
        $this->assertSame([], $this->restore($home, $backup, false));
        foreach (['data', 'session', '.lighttpd'] as $dir) {
            $this->assertTrue(is_dir($home.'/'.$dir));
        }
        $this->assertSame('keep', file_get_contents($backup.'/data/sole-copy'));
    }
}
