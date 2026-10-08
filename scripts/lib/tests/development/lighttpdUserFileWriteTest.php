<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/lighttpd/userFileWrite.php';
require_once __DIR__.'/../common/TestCase.php';

class LighttpdUserFileWriteTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-lighttpd-user-write-']; }

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
        $this->assertStringContainsString('@lchown($path, $owner)', $source);
        $this->assertStringContainsString('@lchgrp($path, ($group === null || $group === \'\') ? $owner : $group)', $source);
        $this->assertFalse(strpos($source, '@chown($path, $owner)') !== false);
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
        $outsidePath = $this->tempDir.'/outside.htpasswd';
        @mkdir(dirname($path), 0755, true);
        file_put_contents($outsidePath, "outside:hash\n");

        $this->assertFalse(\pmssReplaceUserFile($path, "user:hash\n", static function (string $tmp) use ($outsidePath): void {
            @unlink($tmp);
            symlink($outsidePath, $tmp);
        }));

        $this->assertFalse(file_exists($path));
        $this->assertEquals("outside:hash\n", file_get_contents($outsidePath));
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
            ['pmssAppendAccountFile(', 'pmssAppendUserFile(', 'Unable to append legacy credential to per-user htpasswd'],
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
