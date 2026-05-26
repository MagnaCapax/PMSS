<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/user/passwords.php';

class DelugeServicePasswordTest extends TestCase
{
    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-deluge-passwords');
    }

    private function pmssWriteDelugeWebConf(string $path, string $salt, string $password): void
    {
        @mkdir(dirname($path), 0755, true);
        file_put_contents(
            $path,
            json_encode(['file' => 2, 'format' => 1], JSON_UNESCAPED_SLASHES)
            .json_encode(
                [
                    'base' => '/user-alice/deluge/',
                    'first_login' => false,
                    'port' => 8112,
                    'pwd_salt' => $salt,
                    'pwd_sha1' => sha1($salt.$password),
                    'sessions' => (object) [],
                ],
                JSON_UNESCAPED_SLASHES
            )
        );
    }

    public function testGenerateServicePasswordLengthAndCharset(): void
    {
        $password = \pmssDelugeServicePasswordGenerate(32);
        $this->assertEquals(32, strlen($password));
        $this->assertMatches('/^[A-Za-z0-9_-]+$/', $password);
    }

    public function testSharedPasswordAlphabetAvoidsShellMetacharacters(): void
    {
        $password = \pmssDelugeServicePasswordGenerate(96);

        $this->assertMatches('/^[A-Za-z0-9_-]+$/', $password);
        foreach (['!', '@', '#', '$', '%', '&', ':'] as $forbidden) {
            $this->assertTrue(strpos($password, $forbidden) === false, 'Forbidden generated-password character present: '.$forbidden);
        }
    }

    public function testReadLocalclientPasswordReturnsEmptyWhenMissing(): void
    {
        $this->assertEquals('', \pmssDelugeAuthLocalclientPasswordRead($this->tempDir.'/missing-auth'));
    }

    public function testReadLocalclientPasswordParsesEntry(): void
    {
        $authPath = $this->tempDir.'/auth';
        file_put_contents($authPath, "localclient:from-file:10\nuser:abc:5\n");
        $this->assertEquals('from-file', \pmssDelugeAuthLocalclientPasswordRead($authPath));
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
        $webConfPath = $homeRoot.'/alice/.config/deluge/web.conf';
        @mkdir(dirname($authPath), 0755, true);
        file_put_contents($authPath, "localclient:template-token:10\n");
        $this->pmssWriteDelugeWebConf($webConfPath, '9ae84a41deedf34c4ce55ce163df27fd8e8dc3b8', 'template-token');

        $templatePath = $this->tempDir.'/template.deluge.auth';
        file_put_contents($templatePath, "localclient:template-token:10\n");
        $this->pmssTrackEnvOverrides(['PMSS_DELUGE_AUTH_TEMPLATE_PATH' => $templatePath], true);

        $generated = \pmssEnsureDelugeServicePassword('alice');
        $stored = \pmssDelugeAuthLocalclientPasswordRead($authPath);

        $this->assertTrue($generated !== '', 'Expected generated password');
        $this->assertTrue($generated !== 'template-token', 'Expected template token to be rotated');
        $this->assertEquals($generated, $stored);

        $parsed = \pmssDelugeReadWebConf($webConfPath);
        $this->assertTrue(is_array($parsed));
        $this->assertSame($parsed['config']['pwd_sha1'], sha1($parsed['config']['pwd_salt'].$generated));
    }

    public function testEnsureDelugeServicePasswordSynchronizesWebUiHashToExistingAuth(): void
    {
        $homeRoot = $this->pmssTrackHomeRoot($this->tempDir.'/home');
        $authPath = $homeRoot.'/alice/.config/deluge/auth';
        $webConfPath = $homeRoot.'/alice/.config/deluge/web.conf';
        @mkdir(dirname($authPath), 0755, true);
        file_put_contents($authPath, "localclient:existing-secret:10\n");
        $this->pmssWriteDelugeWebConf($webConfPath, '1111111111111111111111111111111111111111', 'stale-secret');

        $ensured = \pmssEnsureDelugeServicePassword('alice');
        $parsed = \pmssDelugeReadWebConf($webConfPath);

        $this->assertSame('existing-secret', $ensured);
        $this->assertTrue(is_array($parsed));
        $this->assertSame('existing-secret', \pmssDelugeAuthLocalclientPasswordRead($authPath));
        $this->assertSame(sha1($parsed['config']['pwd_salt'].$ensured), $parsed['config']['pwd_sha1']);
    }

    public function testRotateDelugeServicePasswordUpdatesAuthAndWebUiTogether(): void
    {
        $homeRoot = $this->pmssTrackHomeRoot($this->tempDir.'/home');
        $authPath = $homeRoot.'/alice/.config/deluge/auth';
        $webConfPath = $homeRoot.'/alice/.config/deluge/web.conf';
        @mkdir(dirname($authPath), 0755, true);
        file_put_contents($authPath, "localclient:old-secret:10\n");
        $this->pmssWriteDelugeWebConf($webConfPath, '2222222222222222222222222222222222222222', 'old-secret');

        $rotated = \pmssDelugeServicePasswordRotate('alice');
        $parsed = \pmssDelugeReadWebConf($webConfPath);

        $this->assertTrue($rotated !== '' && $rotated !== 'old-secret', 'Expected a new Deluge service password');
        $this->assertTrue(is_array($parsed));
        $this->assertSame($rotated, \pmssDelugeAuthLocalclientPasswordRead($authPath));
        $this->assertSame(sha1($parsed['config']['pwd_salt'].$rotated), $parsed['config']['pwd_sha1']);
    }

    public function testRotateDelugeServicePasswordFailsSoftWhenWebConfMissing(): void
    {
        $homeRoot = $this->pmssTrackHomeRoot($this->tempDir.'/home');
        $authPath = $homeRoot.'/alice/.config/deluge/auth';
        @mkdir(dirname($authPath), 0755, true);
        file_put_contents($authPath, "localclient:old-secret:10\n");

        $rotated = \pmssDelugeServicePasswordRotate('alice');

        $this->assertSame('', $rotated);
        $this->assertSame('old-secret', \pmssDelugeAuthLocalclientPasswordRead($authPath));
    }
}
