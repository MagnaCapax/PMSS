<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

final class UserPasswordsCustomerHelperTest extends TestCase
{
    private function helperPath(): string
    {
        return $this->pmssRepoPath('etc/skel/www/userPasswords.php');
    }

    private function runHelperJson(string $script, array $environment = []): array
    {
        return $this->pmssRunInlinePhpRequireJson($this->helperPath(), $script, $environment);
    }

    public function testReadLocalclientPasswordReturnsEmptyWhenMissing(): void
    {
        $path = $this->pmssMakeTempPath('pmss-missing-auth-');
        $result = $this->runHelperJson('echo json_encode(array("password" => pmssDelugeAuthReadLocalclientPassword('.var_export($path, true).')));');

        $this->assertSame('', $result['password']);
    }

    public function testReadLocalclientPasswordParsesValidLine(): void
    {
        $path = $this->pmssMakeTempPath('pmss-deluge-auth-');
        file_put_contents($path, "user:ignored:5\nlocalclient:display-secret:10\n");
        $result = $this->runHelperJson('echo json_encode(array("password" => pmssDelugeAuthReadLocalclientPassword('.var_export($path, true).')));');

        $this->assertSame('display-secret', $result['password']);
    }

    public function testReadLocalclientPasswordRejectsMalformedLine(): void
    {
        $path = $this->pmssMakeTempPath('pmss-deluge-auth-bad-');
        file_put_contents($path, "localclient:bad:secret:10\nlocalclient:has-newline\n:10\n");
        $result = $this->runHelperJson('echo json_encode(array("password" => pmssDelugeAuthReadLocalclientPassword('.var_export($path, true).')));');

        $this->assertSame('', $result['password']);
    }

    public function testReadLocalclientPasswordRejectsSymlink(): void
    {
        $target = $this->pmssMakeTempPath('pmss-deluge-auth-real-');
        $link = $this->pmssMakeTempPath('pmss-deluge-auth-link-');
        file_put_contents($target, "localclient:secret:10\n");

        if (!function_exists('symlink') || @symlink($target, $link) === false) {
            throw new SkipTest('symlink not supported in this environment');
        }

        $result = $this->runHelperJson('echo json_encode(array("password" => pmssDelugeAuthReadLocalclientPassword('.var_export($link, true).')));');

        $this->assertSame('', $result['password']);
    }

    public function testCustomerHelperKeepsRotationInsideCustomerTree(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'etc/skel/www/userPasswords.php',
            ['function pmssDelugeServicePasswordRotate'],
            ["require_once '/scripts", 'require_once "/scripts']
        );
    }

    public function testRotateDelugeServicePasswordUpdatesAuthAndWebUiTogether(): void
    {
        $homeRoot = $this->pmssTrackHomeRoot($this->pmssMakeTempDir('pmss-deluge-home-'));
        $authPath = $homeRoot.'/alice/.config/deluge/auth';
        $webConfPath = $homeRoot.'/alice/.config/deluge/web.conf';
        $salt = '2222222222222222222222222222222222222222';
        @mkdir(dirname($authPath), 0755, true);
        file_put_contents($authPath, "localclient:old-secret:10\n");
        file_put_contents(
            $webConfPath,
            json_encode(array('file' => 2), JSON_UNESCAPED_SLASHES)
            .json_encode(array('pwd_salt' => $salt, 'pwd_sha1' => sha1($salt.'old-secret'), 'sessions' => (object) array()), JSON_UNESCAPED_SLASHES)
        );

        $result = $this->pmssRunInlinePhpRequireJson(
            $this->helperPath(),
            '$rotated = pmssDelugeServicePasswordRotate("alice");'
            .'$parsed = pmssUserPasswordsWebConfRead('.var_export($webConfPath, true).');'
            .'echo json_encode(array("rotated" => $rotated, "parsed" => $parsed));',
            array('PMSS_HOME_DIR' => $homeRoot)
        );

        $rotated = (string) $result['rotated'];
        $parsed = $result['parsed'];
        $this->assertTrue($rotated !== '' && $rotated !== 'old-secret', 'Expected a new Deluge service password');
        $this->assertTrue(is_array($parsed));
        $this->assertStringContainsString("localclient:{$rotated}:10\n", (string) file_get_contents($authPath));
        $this->assertSame(sha1($parsed['config']['pwd_salt'].$rotated), $parsed['config']['pwd_sha1']);
    }

    public function testWelcomePageGatesRotateFormOnRotateHelper(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings(
            'etc/skel/www/welcome.php',
            ["'delugePasswordCanRotate' => \$delugeState['canRotate']", 'if ($delugePasswordCanRotate)', "\$canRotate = function_exists('pmssDelugeServicePasswordRotate')", 'pmssDelugeServicePasswordRotate((string) $username)'],
            ['if ($delugePasswordHelpersAvailable) {']
        );
    }
}
