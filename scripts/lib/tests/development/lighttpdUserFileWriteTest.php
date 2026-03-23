<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/lighttpd/userFileWrite.php';
require_once __DIR__.'/../common/TestCase.php';

class LighttpdUserFileWriteTest extends TestCase
{
    private $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = $this->pmssMakeTempDir('pmss-lighttpd-user-write-');
    }

    protected function tearDown(): void
    {
        $this->pmssRemoveTree($this->tempDir);
    }

    private function currentOwner(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $ownerInfo = @posix_getpwuid(posix_geteuid());
            if (is_array($ownerInfo) && isset($ownerInfo['name'])) {
                return (string) $ownerInfo['name'];
            }
        }

        return '';
    }

    public function testAppendUserFileWritesNewFile(): void
    {
        $path = $this->tempDir.'/user/.lighttpd/.htpasswd';
        @mkdir(dirname($path), 0755, true);

        $this->assertTrue(\pmssAppendUserFile($path, "user:hash\n", $this->currentOwner(), 0640));
        $this->assertEquals("user:hash\n", file_get_contents($path));
        $this->assertEquals(0640, fileperms($path) & 0777);
    }

    public function testAppendUserFilePreservesExistingContent(): void
    {
        $path = $this->tempDir.'/user/.lighttpd/.htpasswd';
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, "first:hash\n");

        $this->assertTrue(\pmssAppendUserFile($path, "second:hash\n", $this->currentOwner(), 0640));
        $this->assertEquals("first:hash\nsecond:hash\n", file_get_contents($path));
    }

    public function testAppendUserFileRejectsSymlinkTarget(): void
    {
        $realPath = $this->tempDir.'/real.htpasswd';
        $linkPath = $this->tempDir.'/link.htpasswd';
        file_put_contents($realPath, "user:hash\n");
        symlink($realPath, $linkPath);

        $this->assertTrue(!\pmssAppendUserFile($linkPath, "other:hash\n", $this->currentOwner(), 0640));
        $this->assertEquals("user:hash\n", file_get_contents($realPath));
    }

    public function testAppendUserFileRejectsMissingParentDirectory(): void
    {
        $path = $this->tempDir.'/missing/.lighttpd/.htpasswd';

        $this->assertTrue(!\pmssAppendUserFile($path, "user:hash\n", $this->currentOwner(), 0640));
        $this->assertTrue(!file_exists($path));
    }

    public function testAppendUserFileRejectsRelativePath(): void
    {
        $this->assertTrue(!\pmssAppendUserFile('relative.htpasswd', "user:hash\n", $this->currentOwner(), 0640));
        $this->assertTrue(!file_exists('relative.htpasswd'));
    }

    public function testWriteUserFileRejectsSymlinkedParentDirectory(): void
    {
        $realDir = $this->tempDir.'/real';
        $linkDir = $this->tempDir.'/linked';
        @mkdir($realDir, 0755, true);
        symlink($realDir, $linkDir);

        $this->assertTrue(!\pmssWriteUserFile($linkDir.'/.htpasswd', "user:hash\n", $this->currentOwner(), 0640));
    }

    public function testWriteUserFileRejectsRelativePath(): void
    {
        $this->assertTrue(!\pmssWriteUserFile('relative.htpasswd', "user:hash\n", $this->currentOwner(), 0640));
        $this->assertTrue(!file_exists('relative.htpasswd'));
    }

    public function testReplaceUserFileCleansTempFileWhenPrepareTempThrows(): void
    {
        $path = $this->tempDir.'/user/.lighttpd/.htpasswd';
        @mkdir(dirname($path), 0755, true);

        $before = glob(dirname($path).'/.htpasswd.pmss-tmp-*');
        $before = is_array($before) ? $before : [];

        try {
            \pmssReplaceUserFile($path, "user:hash\n", static function (): void {
                throw new \RuntimeException('metadata failure');
            });
            $this->fail('Expected pmssReplaceUserFile() to rethrow callback failure.');
        } catch (\RuntimeException $exception) {
            $this->assertEquals('metadata failure', $exception->getMessage());
        }

        $after = glob(dirname($path).'/.htpasswd.pmss-tmp-*');
        $after = is_array($after) ? $after : [];

        $this->assertEquals($before, $after);
        $this->assertTrue(!file_exists($path));
    }

    public function testReplaceUserFileRejectsPrepareTempSymlinkSwap(): void
    {
        $path = $this->tempDir.'/user/.lighttpd/.htpasswd';
        $outsidePath = $this->tempDir.'/outside.htpasswd';
        @mkdir(dirname($path), 0755, true);
        file_put_contents($outsidePath, "outside:hash\n");

        $this->assertTrue(!\pmssReplaceUserFile($path, "user:hash\n", static function (string $tmp) use ($outsidePath): void {
            @unlink($tmp);
            symlink($outsidePath, $tmp);
        }));

        $this->assertTrue(!file_exists($path));
        $this->assertEquals("outside:hash\n", file_get_contents($outsidePath));
    }

    public function testCheckUserHtpasswdUsesSafeAppendHelper(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/checkUserHtpasswd.php');

        $this->assertStringContainsString('pmssAppendUserFile(', $src);
        $this->assertStringContainsString('Unable to append legacy credential to per-user htpasswd', $src);
        $this->assertTrue(strpos($src, 'file_put_contents($userHtpasswd') === false);
    }
}
