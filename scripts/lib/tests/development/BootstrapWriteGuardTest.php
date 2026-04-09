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

    public function testManagedPathWriterStoresContentWithRootMetadata(): void
    {
        $path = $this->tempDir.'/etc/hostname';
        $this->pmssEnsureFixtureDirectory(dirname($path));

        $this->assertTrue(\pmssWriteManagedPathFile($path, "pmss-host\n", 'hostname', 'logMessage', 'root', 'root'));
        $this->assertEquals("pmss-host\n", $this->pmssReadFileOrEmpty($path));
        $this->assertEquals(0644, fileperms($path) & 0777);
    }

    public function testManagedPathWriterReplacesExistingRegularFile(): void
    {
        $path = $this->tempDir.'/etc/ssh/sshd_config';
        $this->pmssWriteFile($path, "old\n");

        $this->assertTrue(\pmssWriteManagedPathFile($path, "new\n", 'sshd config', 'logMessage', 'root', 'root'));
        $this->assertEquals("new\n", $this->pmssReadFileOrEmpty($path));
    }

    public function testManagedPathWriterRejectsSymlinkTarget(): void
    {
        $realPath = $this->tempDir.'/real';
        $linkPath = $this->tempDir.'/link';
        $this->pmssWriteFile($realPath, "keep\n");
        symlink($realPath, $linkPath);

        $this->assertFalse(\pmssWriteManagedPathFile($linkPath, "replace\n", 'sshd config', 'logMessage', 'root', 'root'));
        $this->assertEquals("keep\n", $this->pmssReadFileOrEmpty($realPath));
    }

    public function testManagedPathWriterRejectsRelativePath(): void
    {
        $this->assertFalse(\pmssWriteManagedPathFile('relative-sshd_config', "nope\n", 'sshd config', 'logMessage', 'root', 'root'));
        $this->assertFalse(file_exists('relative-sshd_config'));
    }

    public function testBootstrapUsesSharedManagedPathWriterForHostnameAndSshdConfig(): void
    {
        $path = 'scripts/lib/update/services/bootstrap.php';
        $this->pmssAssertRepoFileContainsAllStrings($path, [
            "'../managedPath.php'",
            "pmssWriteManagedPathFile('/etc/hostname', \$hostname.PHP_EOL, 'hostname', \$log, 'root', 'root')",
            'pmssSshdConfigWriteUpdated(',
            'pmssSshdAuthorizedKeysDirectiveNormalize(',
        ]);
        $this->pmssAssertRepoFileNotContainsStrings($path, ["file_put_contents('/etc/hostname'", 'file_put_contents($sshdConfig, $updated)']);
    }
}
