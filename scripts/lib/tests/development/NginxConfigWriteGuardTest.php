<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/nginxConfig/userConfigsGenerate.php';
require_once __DIR__.'/../common/TestCase.php';

/**
 * Verify nginx config generation uses guarded file writes.
 */
class NginxConfigWriteGuardTest extends TestCase
{
    private $tempDir = '';

    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-nginx-config-write-');
    }

    public function testWriteFileStoresContentWithManagedPermissions(): void
    {
        $path = $this->tempDir.'/conf.d/pmss-user-alice.conf';
        @mkdir(dirname($path), 0755, true);

        $this->assertTrue(\pmssCreateNginxConfigWriteFile($path, "server {}\n", 'alice', 'public subdomain config'));
        $this->assertEquals("server {}\n", (string) file_get_contents($path));
        $this->assertEquals(0640, fileperms($path) & 0777);
    }

    public function testWriteFileReplacesExistingRegularFile(): void
    {
        $path = $this->tempDir.'/users/alice';
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, "old\n");

        $this->assertTrue(\pmssCreateNginxConfigWriteFile($path, "new\n", 'alice', 'user config'));
        $this->assertEquals("new\n", (string) file_get_contents($path));
    }

    public function testWriteFileRejectsSymlinkTarget(): void
    {
        $realPath = $this->tempDir.'/real.conf';
        $linkPath = $this->tempDir.'/link.conf';
        file_put_contents($realPath, "keep\n");
        symlink($realPath, $linkPath);

        $this->assertFalse(\pmssCreateNginxConfigWriteFile($linkPath, "replace\n", 'alice', 'user config'));
        $this->assertEquals("keep\n", (string) file_get_contents($realPath));
    }

    public function testWriteFileRejectsMissingParentDirectory(): void
    {
        $path = $this->tempDir.'/missing/pmss-user-alice.conf';

        $this->assertFalse(\pmssCreateNginxConfigWriteFile($path, "server {}\n", 'alice', 'public subdomain config'));
        $this->assertFalse(file_exists($path));
    }

    public function testGeneratorUsesGuardedWriterForNginxOutputs(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/nginxConfig/userConfigsGenerate.php', [
            "require_once __DIR__.'/../lighttpd/userFileWrite.php';",
            'function pmssCreateNginxConfigWriteFile(string $path, string $content, string $user, string $label): bool',
            'pmssWriteManagedFile($path, $content, \'root\', \'root\', 0640)',
        ]);
        $this->pmssAssertRepoFileNotContainsStrings('scripts/lib/nginxConfig/userConfigsGenerate.php', [
            'file_put_contents($subdomainConfigDir',
            'file_put_contents("/etc/nginx/users/',
        ]);
    }
}
