<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/lighttpd/userFileWrite.php';
require_once __DIR__.'/../common/TestCase.php';

class LighttpdUserFileWriteTest extends TestCase
{
    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-lighttpd-user-write-');
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

    public function testWriteUserFileRejectsSymlinkedParentDirectory(): void
    {
        [, $linkDir] = $this->pmssCreateSymlinkedDirectoryOrSkip($this->tempDir.'/real', $this->tempDir.'/linked');

        $this->assertFalse(\pmssWriteUserFile($linkDir.'/.htpasswd', "user:hash\n", $this->pmssCurrentOwner(), 0640));
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

    public function testCheckUserHtpasswdUsesSafeAppendHelper(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'scripts/util/checkUserHtpasswd.php',
            ['pmssAppendUserFile(', 'Unable to append legacy credential to per-user htpasswd'],
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

    /** Limit writes only in a child process; all files remain in test fixtures. */
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
            .'$path = '.var_export($path, true).'; $payload = '.var_export($payload, true).'; $prepared = false;'
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
