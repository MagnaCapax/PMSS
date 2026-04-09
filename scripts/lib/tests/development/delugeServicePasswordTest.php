<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/user/passwords.php';

class DelugeServicePasswordTest extends TestCase
{
    private $tempDir;

    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-deluge-passwords');
    }

    public function testGenerateServicePasswordLengthAndCharset(): void
    {
        $password = \pmssDelugeServicePasswordGenerate(32);
        $this->assertEquals(32, strlen($password));
        $this->assertMatches('/^[A-Za-z0-9!@#$%]+$/', $password);
    }

    public function testReadLocalclientPasswordReturnsEmptyWhenMissing(): void
    {
        $this->assertEquals('', \pmssDelugeAuthReadLocalclientPassword($this->tempDir.'/missing-auth'));
    }

    public function testReadLocalclientPasswordParsesEntry(): void
    {
        $authPath = $this->tempDir.'/auth';
        file_put_contents($authPath, "localclient:from-file:10\nuser:abc:5\n");
        $this->assertEquals('from-file', \pmssDelugeAuthReadLocalclientPassword($authPath));
    }

    public function testWriteLocalclientPasswordReplacesExistingEntry(): void
    {
        $authPath = $this->tempDir.'/auth';
        file_put_contents($authPath, "localclient:old:10\nuser:abc:5\n");

        $this->assertTrue(\pmssDelugeAuthWriteLocalclientPassword($authPath, 'new-password'));
        $content = (string) file_get_contents($authPath);

        $this->assertStringContainsString("localclient:new-password:10\n", $content);
        $this->assertTrue(strpos($content, 'localclient:old:10') === false, 'Old localclient secret should be replaced');
    }

    public function testWriteLocalclientPasswordAppendsWhenMissing(): void
    {
        $authPath = $this->tempDir.'/auth';
        file_put_contents($authPath, "user:abc:5\n");

        $this->assertTrue(\pmssDelugeAuthWriteLocalclientPassword($authPath, 'new-secret'));
        $this->assertStringContainsString("localclient:new-secret:10\n", (string) file_get_contents($authPath));
    }

    public function testWriteLocalclientPasswordRejectsSymlinkTarget(): void
    {
        $realPath = $this->tempDir.'/auth-real';
        $linkPath = $this->tempDir.'/auth-link';
        file_put_contents($realPath, "localclient:original:10\n");

        if (!function_exists('symlink') || @symlink($realPath, $linkPath) === false) {
            throw new SkipTest('symlink not supported in this environment');
        }

        $this->assertTrue(!\pmssDelugeAuthWriteLocalclientPassword($linkPath, 'new-secret'));
        $this->assertEquals("localclient:original:10\n", (string) file_get_contents($realPath));
    }

    public function testWriteLocalclientPasswordRejectsPathUnderSymlinkedParent(): void
    {
        $realDir = $this->tempDir.'/real-dir';
        $linkDir = $this->tempDir.'/link-dir';
        @mkdir($realDir, 0755, true);

        if (!function_exists('symlink') || @symlink($realDir, $linkDir) === false) {
            throw new SkipTest('symlink not supported in this environment');
        }

        $this->assertTrue(!\pmssDelugeAuthWriteLocalclientPassword($linkDir.'/auth', 'new-secret'));
        $this->assertTrue(!file_exists($realDir.'/auth'));
    }

    public function testEnsureDelugeServicePasswordRotatesTemplateToken(): void
    {
        $homeRoot = $this->pmssTrackHomeRoot($this->tempDir.'/home');
        $authPath = $homeRoot.'/alice/.config/deluge/auth';
        @mkdir(dirname($authPath), 0755, true);
        file_put_contents($authPath, "localclient:template-token:10\n");

        $templatePath = $this->tempDir.'/template.deluge.auth';
        file_put_contents($templatePath, "localclient:template-token:10\n");
        $this->pmssTrackEnvOverrides(['PMSS_DELUGE_AUTH_TEMPLATE_PATH' => $templatePath], true);

        $generated = \pmssEnsureDelugeServicePassword('alice');
        $stored = \pmssDelugeAuthReadLocalclientPassword($authPath);

        $this->assertTrue($generated !== '', 'Expected generated password');
        $this->assertTrue($generated !== 'template-token', 'Expected template token to be rotated');
        $this->assertEquals($generated, $stored);
    }
}
