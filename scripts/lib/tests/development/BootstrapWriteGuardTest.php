<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/update/managedPath.php';
require_once dirname(__DIR__, 2).'/update/services/bootstrap.php';

/**
 * Verify bootstrap config writers stay on the shared guarded managed-path flow.
 */
class BootstrapWriteGuardTest extends TestCase
{
    private $tempDir = '';

    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-bootstrap-write-');
    }

    protected function tearDown(): void
    {
        $this->pmssCleanupTempDirProperty('tempDir');
    }

    public function testManagedPathWriterStoresContentWithRootMetadata(): void
    {
        $path = $this->tempDir.'/etc/hostname';
        @mkdir(dirname($path), 0755, true);

        $this->assertTrue(\pmssWriteManagedPathFile($path, "pmss-host\n", 'hostname', 'logMessage', 'root', 'root'));
        $this->assertEquals("pmss-host\n", (string) file_get_contents($path));
        $this->assertEquals(0644, fileperms($path) & 0777);
    }

    public function testManagedPathWriterReplacesExistingRegularFile(): void
    {
        $path = $this->tempDir.'/etc/ssh/sshd_config';
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, "old\n");

        $this->assertTrue(\pmssWriteManagedPathFile($path, "new\n", 'sshd config', 'logMessage', 'root', 'root'));
        $this->assertEquals("new\n", (string) file_get_contents($path));
    }

    public function testManagedPathWriterRejectsSymlinkTarget(): void
    {
        $realPath = $this->tempDir.'/real';
        $linkPath = $this->tempDir.'/link';
        file_put_contents($realPath, "keep\n");
        symlink($realPath, $linkPath);

        $this->assertFalse(\pmssWriteManagedPathFile($linkPath, "replace\n", 'sshd config', 'logMessage', 'root', 'root'));
        $this->assertEquals("keep\n", (string) file_get_contents($realPath));
    }

    public function testManagedPathWriterRejectsRelativePath(): void
    {
        $this->assertFalse(\pmssWriteManagedPathFile('relative-sshd_config', "nope\n", 'sshd config', 'logMessage', 'root', 'root'));
        $this->assertFalse(file_exists('relative-sshd_config'));
    }

    public function testBootstrapUsesSharedManagedPathWriterForHostnameAndSshdConfig(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/update/services/bootstrap.php');

        $this->assertStringContainsString("'../managedPath.php'", $source);
        $this->assertStringContainsString("pmssWriteManagedPathFile('/etc/hostname', \$hostname.PHP_EOL, 'hostname', \$log, 'root', 'root')", $source);
        $this->assertStringContainsString("pmssWriteManagedPathFile('/etc/ssh/pmss.sshd_config', \$config, 'sshd backup config', 'logMessage', 'root', 'root')", $source);
        $this->assertStringContainsString("pmssWriteManagedPathFile(\$sshdConfig, \$updated, 'sshd config', 'logMessage', 'root', 'root')", $source);
        $this->assertFalse(strpos($source, "file_put_contents('/etc/hostname'") !== false);
        $this->assertFalse(strpos($source, 'file_put_contents($sshdConfig, $updated)') !== false);
    }
}
