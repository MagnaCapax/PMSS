<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/services/bootstrap.php';

/**
 * Verify bootstrap config writers keep guarded root-owned writes.
 */
class BootstrapWriteGuardTest extends TestCase
{
    private $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = $this->pmssMakeTempDir('pmss-bootstrap-write-');
    }

    protected function tearDown(): void
    {
        $this->pmssRemoveTree($this->tempDir);
    }

    public function testRootOwnedWriterStoresContent(): void
    {
        $path = $this->tempDir.'/etc/hostname';
        @mkdir(dirname($path), 0755, true);

        $this->assertTrue(\pmssBootstrapWriteRootOwnedFile($path, "pmss-host\n"));
        $this->assertEquals("pmss-host\n", (string) file_get_contents($path));
        $this->assertEquals(0644, fileperms($path) & 0777);
    }

    public function testRootOwnedWriterReplacesExistingRegularFile(): void
    {
        $path = $this->tempDir.'/etc/ssh/sshd_config';
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, "old\n");

        $this->assertTrue(\pmssBootstrapWriteRootOwnedFile($path, "new\n"));
        $this->assertEquals("new\n", (string) file_get_contents($path));
    }

    public function testRootOwnedWriterRejectsSymlinkTarget(): void
    {
        $realPath = $this->tempDir.'/real';
        $linkPath = $this->tempDir.'/link';
        file_put_contents($realPath, "keep\n");
        symlink($realPath, $linkPath);

        $this->assertFalse(\pmssBootstrapWriteRootOwnedFile($linkPath, "replace\n"));
        $this->assertEquals("keep\n", (string) file_get_contents($realPath));
    }

    public function testRootOwnedWriterRejectsRelativePath(): void
    {
        $this->assertFalse(\pmssBootstrapWriteRootOwnedFile('relative-sshd_config', "nope\n"));
        $this->assertFalse(file_exists('relative-sshd_config'));
    }

    public function testBootstrapUsesGuardedWriterForHostnameAndSshdConfig(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/update/services/bootstrap.php');

        $this->assertStringContainsString("'../../lighttpd/userFileWrite.php'", $source);
        $this->assertStringContainsString('function pmssBootstrapWriteRootOwnedFile(string $path, string $contents, ?callable $logger = null): bool', $source);
        $this->assertStringContainsString('pmssWriteManagedFile($path, $contents, \'root\', \'root\', 0644)', $source);
        $this->assertStringContainsString("pmssBootstrapWriteRootOwnedFile('/etc/hostname', $hostname.PHP_EOL, $log)", $source);
        $this->assertStringContainsString('pmssBootstrapWriteRootOwnedFile($sshdConfig, $updated)', $source);
        $this->assertFalse(strpos($source, "file_put_contents('/etc/hostname'") !== false);
        $this->assertFalse(strpos($source, 'file_put_contents($sshdConfig, $updated)') !== false);
    }
}
