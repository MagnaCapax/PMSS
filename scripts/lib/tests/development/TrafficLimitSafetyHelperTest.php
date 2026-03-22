<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/scripts/lib/user/directories.php';
require_once dirname(__DIR__, 4).'/scripts/lib/user/trafficLimit.php';

class TrafficLimitSafetyHelperTest extends TestCase
{
    private $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = $this->pmssMakeTempDir('pmss-traffic-limit-safety-');
    }

    protected function tearDown(): void
    {
        $this->pmssRemoveTree($this->tempDir);
    }

    public function testEnsureStorageDirRejectsRelativePath(): void
    {
        $this->assertTrue(\pmssTrafficLimitEnsureStorageDir('relative/path') === false);
    }

    public function testEnsureStorageDirRejectsExistingFile(): void
    {
        $path = $this->tempDir.'/not-a-dir';
        file_put_contents($path, 'x');

        $this->assertTrue(\pmssTrafficLimitEnsureStorageDir($path) === false);
    }

    public function testEnsureStorageDirRejectsSymlink(): void
    {
        $realDir = $this->tempDir.'/real';
        $linkDir = $this->tempDir.'/link';
        mkdir($realDir, 0755, true);
        symlink($realDir, $linkDir);

        $this->assertTrue(\pmssTrafficLimitEnsureStorageDir($linkDir) === false);
    }

    public function testEnsureStorageDirCreatesDirectoryWithStrictMode(): void
    {
        $path = $this->tempDir.'/runtime/trafficLimits';

        $this->assertTrue(\pmssTrafficLimitEnsureStorageDir($path));
        $this->assertTrue(is_dir($path));
        $this->assertEquals(0700, fileperms($path) & 0777);
    }

    public function testEnsureStorageDirConvergesExistingDirectoryMode(): void
    {
        $path = $this->tempDir.'/existing';
        mkdir($path, 0755, true);
        chmod($path, 0755);

        $this->assertTrue(\pmssTrafficLimitEnsureStorageDir($path));
        $this->assertEquals(0700, fileperms($path) & 0777);
    }

    public function testRemoveGiBFileTreatsMissingFileAsSuccess(): void
    {
        $this->assertTrue(\pmssTrafficLimitRemoveGiBFile($this->tempDir.'/missing'));
    }

    public function testRemoveGiBFileDeletesRegularFile(): void
    {
        $path = $this->tempDir.'/quota';
        file_put_contents($path, '12');

        $this->assertTrue(\pmssTrafficLimitRemoveGiBFile($path));
        $this->assertTrue(!file_exists($path));
    }

    public function testRemoveGiBFileRejectsSymlink(): void
    {
        $realPath = $this->tempDir.'/real';
        $linkPath = $this->tempDir.'/link';
        file_put_contents($realPath, '12');
        symlink($realPath, $linkPath);

        $this->assertTrue(\pmssTrafficLimitRemoveGiBFile($linkPath) === false);
        $this->assertTrue(file_exists($realPath));
    }

    public function testRemoveGiBFileRejectsDirectory(): void
    {
        $path = $this->tempDir.'/dir';
        mkdir($path, 0755, true);

        $this->assertTrue(\pmssTrafficLimitRemoveGiBFile($path) === false);
    }

    public function testConvergeFileModeAppliesRequestedMode(): void
    {
        $path = $this->tempDir.'/quota';
        file_put_contents($path, '12');
        chmod($path, 0644);

        $this->assertTrue(\pmssTrafficLimitConvergeFileMode($path, 0600));
        $this->assertEquals(0600, fileperms($path) & 0777);
    }

    public function testConvergeFileModeAcceptsExistingDirectoryMode(): void
    {
        $path = $this->tempDir.'/dir';
        mkdir($path, 0700, true);

        $this->assertTrue(\pmssTrafficLimitConvergeFileMode($path, 0700));
    }

    public function testConvergeFileModeRejectsSymlink(): void
    {
        $realPath = $this->tempDir.'/real';
        $linkPath = $this->tempDir.'/link';
        file_put_contents($realPath, '12');
        symlink($realPath, $linkPath);

        $this->assertTrue(\pmssTrafficLimitConvergeFileMode($linkPath, 0600) === false);
    }
}
