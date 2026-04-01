<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/lighttpd/userFileWrite.php';
require_once __DIR__.'/../common/TestCase.php';

class LighttpdUserFileWriteTest extends TestCase
{
    private $tempDir = '';

    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-lighttpd-user-write-');
    }

    protected function tearDown(): void
    {
        $this->pmssCleanupTempDirProperty('tempDir');
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
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, "first:hash\n");

        $this->assertTrue(\pmssAppendUserFile($path, "second:hash\n", $this->pmssCurrentOwner(), 0640));
        $this->assertEquals("first:hash\nsecond:hash\n", file_get_contents($path));
    }

    public function testAppendUserFileRejectsSymlinkTarget(): void
    {
        $realPath = $this->tempDir.'/real.htpasswd';
        $linkPath = $this->tempDir.'/link.htpasswd';
        file_put_contents($realPath, "user:hash\n");
        symlink($realPath, $linkPath);

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
        $realDir = $this->tempDir.'/real';
        $linkDir = $this->tempDir.'/linked';
        @mkdir($realDir, 0755, true);
        symlink($realDir, $linkDir);

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

        $this->assertFalse(\pmssReplaceUserFile($path, "user:hash\n", static function (string $tmp) use ($managedDir, $movedDir): void {
            rename($managedDir, $movedDir);
            symlink($movedDir, $managedDir);
            clearstatcache(true, $tmp);
        }));

        $this->assertTrue(is_link($managedDir));
        $this->assertFalse(file_exists($path));
        $this->assertFalse(file_exists($movedDir.'/.htpasswd'));
        $this->assertEquals(0, count(glob($movedDir.'/.htpasswd.pmss-tmp-*') ?: []));
    }

    public function testCheckUserHtpasswdUsesSafeAppendHelper(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/checkUserHtpasswd.php');

        $this->assertStringContainsString('pmssAppendUserFile(', $src);
        $this->assertStringContainsString('Unable to append legacy credential to per-user htpasswd', $src);
        $this->assertFalse(strpos($src, 'file_put_contents($userHtpasswd') !== false);
    }
}
