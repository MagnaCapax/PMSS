<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/nginxConfig/userConfigsGenerate.php';
require_once __DIR__.'/../common/TestCase.php';

/**
 * Verify nginx config generation uses guarded file writes.
 */
class NginxConfigWriteGuardTest extends TestCase
{
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
        $this->pmssWriteFile($path, "old\n");

        $this->assertTrue(\pmssCreateNginxConfigWriteFile($path, "new\n", 'alice', 'user config'));
        $this->assertEquals("new\n", (string) file_get_contents($path));
    }

    public function testWriteFileRejectsSymlinkTarget(): void
    {
        [$realPath, $linkPath] = $this->pmssCreateSymlinkedFileOrSkip($this->tempDir.'/real.conf', $this->tempDir.'/link.conf', "keep\n");

        $this->assertFalse(\pmssCreateNginxConfigWriteFile($linkPath, "replace\n", 'alice', 'user config'));
        $this->assertEquals("keep\n", (string) file_get_contents($realPath));
    }

    public function testWriteFileRejectsMissingParentDirectory(): void
    {
        $path = $this->tempDir.'/missing/pmss-user-alice.conf';

        $this->assertFalse(\pmssCreateNginxConfigWriteFile($path, "server {}\n", 'alice', 'public subdomain config'));
        $this->assertFalse(file_exists($path));
    }

    public function testRemoveFileDeletesRegularFile(): void
    {
        $path = $this->pmssWriteFile($this->tempDir.'/users/alice', "old\n");

        $this->assertTrue(\pmssCreateNginxConfigRemoveFile($path, 'alice', 'stale user config'));
        $this->assertFalse(file_exists($path));
    }

    public function testRemoveFileTreatsMissingFileAsConverged(): void
    {
        $path = $this->tempDir.'/users/missing';
        @mkdir(dirname($path), 0755, true);

        $this->assertTrue(\pmssCreateNginxConfigRemoveFile($path, 'alice', 'stale user config'));
        $this->assertFalse(file_exists($path));
    }

    public function testRemoveFileRejectsSymlinkTarget(): void
    {
        [$realPath, $linkPath] = $this->pmssCreateSymlinkedFileOrSkip($this->tempDir.'/real.conf', $this->tempDir.'/link.conf', "keep\n");

        $this->assertFalse(\pmssCreateNginxConfigRemoveFile($linkPath, 'alice', 'stale user config'));
        $this->assertEquals("keep\n", (string) file_get_contents($realPath));
        $this->assertTrue(is_link($linkPath), 'symlink target must remain untouched');
    }

    public function testRemoveFileRejectsDirectoryTarget(): void
    {
        $path = $this->tempDir.'/users/alice';
        @mkdir($path, 0755, true);

        $this->assertFalse(\pmssCreateNginxConfigRemoveFile($path, 'alice', 'stale user config'));
        $this->assertTrue(is_dir($path), 'directory target must remain untouched');
    }

    public function testExtractedGenerationHelpersKeepSubdomainAndLegacyDelugeContracts(): void
    {
        $configDir = $this->tempDir.'/conf.d';
        @mkdir($configDir, 0755, true);
        $ctx = ['subdomainConfigDir' => $configDir, 'nginxSslBlock' => 'ssl-on', 'publicSubdomainTemplate' => 'public ##host## ##user## ##port## ##ssl_block##', 'privateSubdomainTemplate' => 'private ##host## ##user## ##port## ##ssl_block##'];
        $this->assertTrue(\pmssCreateNginxConfigWriteSubdomainConfigs($ctx, 'alice', 'example.test', 'hash.example.test', false, 12345));
        $this->assertEquals('public alice.example.test alice 12345 ssl-on', (string) file_get_contents($configDir.'/pmss-user-alice.conf'));
        $this->assertEquals('private hash.example.test alice 12345 ssl-on', (string) file_get_contents($configDir.'/pmss-user-alice-hash.conf'));

        $homeDir = $this->tempDir.'/home/alice';
        @mkdir($homeDir, 0755, true);
        $target = $this->pmssWriteFile($this->tempDir.'/outside-port', "62000\n");
        if (!@symlink($target, $homeDir.'/.delugeWebPort')) throw new SkipTest('symlink unavailable');
        $this->pmssWriteFile($homeDir.'/.delugePort', "65535\n");
        $this->assertEquals(1, \pmssCreateNginxConfigLegacyDelugeWebPort($homeDir, 'alice'));
    }

    public function testGeneratorUsesGuardedWriterForNginxOutputs(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/nginxConfig/userConfigsGenerate.php', [
            "require_once __DIR__.'/../lighttpd/userFileWrite.php';",
            'function pmssCreateNginxConfigWriteFile(string $path, string $content, string $user, string $label): bool',
            'pmssWriteManagedFile($path, $content, \'root\', \'root\', 0640)',
            'function pmssCreateNginxConfigRemoveFile(string $path, string $user, string $label): bool',
            'pmssCreateNginxConfigRemoveFile("/etc/nginx/users/{$thisUser}", $thisUser, \'stale user config\');',
        ]);
        $this->pmssAssertRepoFileNotContainsStrings('scripts/lib/nginxConfig/userConfigsGenerate.php', [
            'file_put_contents($subdomainConfigDir',
            'file_put_contents("/etc/nginx/users/',
            '@unlink("/etc/nginx/users/',
        ]);
    }
}
