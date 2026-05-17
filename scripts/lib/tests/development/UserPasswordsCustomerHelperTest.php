<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 4).'/etc/skel/www/userPasswords.php';

final class UserPasswordsCustomerHelperTest extends TestCase
{
    public function testReadLocalclientPasswordReturnsEmptyWhenMissing(): void
    {
        $this->assertSame('', \pmssDelugeAuthReadLocalclientPassword($this->pmssMakeTempPath('pmss-missing-auth-')));
    }

    public function testReadLocalclientPasswordParsesValidLine(): void
    {
        $path = $this->pmssMakeTempPath('pmss-deluge-auth-');
        file_put_contents($path, "user:ignored:5\nlocalclient:display-secret:10\n");

        $this->assertSame('display-secret', \pmssDelugeAuthReadLocalclientPassword($path));
    }

    public function testReadLocalclientPasswordRejectsMalformedLine(): void
    {
        $path = $this->pmssMakeTempPath('pmss-deluge-auth-bad-');
        file_put_contents($path, "localclient:bad:secret:10\nlocalclient:has-newline\n:10\n");

        $this->assertSame('', \pmssDelugeAuthReadLocalclientPassword($path));
    }

    public function testReadLocalclientPasswordRejectsSymlink(): void
    {
        $target = $this->pmssMakeTempPath('pmss-deluge-auth-real-');
        $link = $this->pmssMakeTempPath('pmss-deluge-auth-link-');
        file_put_contents($target, "localclient:secret:10\n");

        if (!function_exists('symlink') || @symlink($target, $link) === false) {
            throw new SkipTest('symlink not supported in this environment');
        }

        $this->assertSame('', \pmssDelugeAuthReadLocalclientPassword($link));
    }

    public function testCustomerHelperStaysDisplayOnly(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/userPasswords.php');
        $webConfWriteSymbol = 'pmssDelugeWebConf'.'Write';

        $this->assertStringNotContainsString('function pmssDelugeServicePasswordRotate', $source);
        $this->assertStringNotContainsString('function pmssDelugeAuthWriteLocalclientPassword', $source);
        $this->assertStringNotContainsString('function '.$webConfWriteSymbol, $source);
    }

    public function testWelcomePageGatesRotateFormOnRotateHelper(): void
    {
        $source = $this->pmssReadRepoFile('etc/skel/www/welcome.php');

        $this->assertStringContainsString("'delugePasswordCanRotate' => \$delugeState['canRotate']", $source);
        $this->assertStringContainsString('if ($delugePasswordCanRotate)', $source);
        $this->assertStringContainsString("'canRotate' => false", $source);
        $this->assertStringNotContainsString('pmssDelugeServicePasswordRotate(', $source);
        $this->assertStringNotContainsString('if ($delugePasswordHelpersAvailable) {', $source);
    }
}
