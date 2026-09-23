<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/util/userConfigLighttpd.php';

class LighttpdUserDirectoryPrepTest extends TestCase
{
    private $base;

    protected function pmssTempDirFixtureArguments(): array { return ['base', 'pmss-lighttpd-dirprep-', 0700]; }

    public function testCreatesDirectoriesAndCustomFile(): void
    {
        $user = 'testuser';
        $home = $this->pmssEnsureUserWebHome($this->base, 'home');

        $ok = \pmssPrepareLighttpdUserDirectories($user, $home, true);
        $this->assertTrue($ok);

        $this->assertTrue(is_dir($home.'/.lighttpd'));
        $this->assertTrue(is_dir($home.'/.lighttpd/custom.d'));
        $this->assertTrue(is_dir($home.'/.lighttpd/upload'));
        $this->assertTrue(is_dir($home.'/.lighttpd/compress'));
        $this->assertTrue(is_dir($home.'/www/public'));
        $this->assertTrue(is_file($home.'/.lighttpd/custom'));
        $this->assertEquals(0640, (fileperms($home.'/.lighttpd/custom') & 0777));

        $this->assertEquals(0751, (@fileperms($home.'/.lighttpd') & 0777));
        $this->assertEquals(0750, (@fileperms($home.'/.lighttpd/custom.d') & 0777));
        $this->assertEquals(0751, (@fileperms($home.'/.lighttpd/upload') & 0777));
        $this->assertEquals(0751, (@fileperms($home.'/.lighttpd/compress') & 0777));
        $this->assertEquals(0751, (@fileperms($home.'/www/public') & 0777));
    }

    public function testExistingCustomFileModeConvergesWithoutRewritingContent(): void
    {
        $home = $this->pmssEnsureUserWebHome($this->base, 'home');
        $this->pmssEnsureDir($home.'/.lighttpd');
        $path = $home.'/.lighttpd/custom';
        $content = "# Customer configuration\nserver.tag = \"custom\"\n";
        file_put_contents($path, $content);
        touch($path, 1234567890);
        $inode = fileinode($path);

        foreach ([0751, 0750, 0644, 0640, 0640] as $mode) {
            chmod($path, $mode);
            $this->assertTrue(\pmssPrepareLighttpdUserDirectories('testuser', $home, false));
            clearstatcache(true, $path);
            $this->assertSame(0640, fileperms($path) & 0777);
            $this->assertSame($content, file_get_contents($path));
            $this->assertSame($inode, fileinode($path));
            $this->assertSame(1234567890, filemtime($path));
        }
    }

    public function testRejectsUnsafeCustomFilesWithoutChangingTargets(): void
    {
        $target = $this->base.'/target';
        file_put_contents($target, 'untouched');
        chmod($target, 0600);
        foreach (['symlink', 'dangling', 'directory'] as $kind) {
            $home = $this->pmssEnsureUserWebHome($this->base, $kind);
            $this->pmssEnsureDir($home.'/.lighttpd');
            $path = $home.'/.lighttpd/custom';
            if ($kind === 'directory') {
                mkdir($path, 0700);
            } else {
                $this->pmssCreateSymlinkOrSkip($target.($kind === 'dangling' ? '-missing' : ''), $path);
            }
            $this->assertSame(false, \pmssPrepareLighttpdUserDirectories('testuser', $home, false));
            clearstatcache(true, $target);
            $this->assertSame(0600, fileperms($target) & 0777);
            $this->assertSame('untouched', file_get_contents($target));
            $this->assertTrue($kind === 'directory' ? is_dir($path) : is_link($path));
        }
    }

    public function testRejectsInvalidUsername(): void
    {
        $home = $this->base.'/home';
        $this->pmssEnsureDir($home);
        $ok = \pmssPrepareLighttpdUserDirectories('bad/user', $home, false);
        $this->assertTrue($ok === false);
    }

    public function testRejectsSymlinkedLighttpdDir(): void
    {
        $user = 'testuser';
        $home = $this->pmssEnsureUserWebHome($this->base, 'home');

        $elsewhere = $this->base.'/elsewhere';
        $this->pmssEnsureDir($elsewhere, 0700);
        @symlink($elsewhere, $home.'/.lighttpd');

        $ok = \pmssPrepareLighttpdUserDirectories($user, $home, true);
        $this->assertTrue($ok === false);
        $this->assertTrue(is_link($home.'/.lighttpd'));
        $this->assertTrue(!is_dir($elsewhere.'/custom.d'));
    }

    public function testAcceptsExpectedDurablePublicWebRootSymlink(): void
    {
        $user = 'testuser';
        $home = $this->pmssEnsureUserWebHome($this->base, 'home');
        $target = $home.'/.local/share/pmss/public';
        $this->pmssEnsureDir($target, 0751);
        $this->pmssCreateSymlinkOrSkip('../.local/share/pmss/public', $home.'/www/public');

        $this->assertTrue(\pmssPrepareLighttpdUserDirectories($user, $home, false));
        $this->assertSame('../.local/share/pmss/public', readlink($home.'/www/public'));
    }
}
