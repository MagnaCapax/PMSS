<?php
/**
 * Per-user directory ensure helper tests.
 */

namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/directories.php';

class UserDirectoriesEnsureTest extends TestCase
{
    private $tempDir;
    private $user;

    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-user-dir-ensure-', 0700);

        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $pw = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : null;
        $this->user = (is_array($pw) && isset($pw['name']) && is_string($pw['name'])) ? $pw['name'] : get_current_user();
    }

    private function recursiveDelete(string $dir): void
    {
        $this->cleanup($dir);
    }

    public function testCreatesNestedPathWithParentModeAndLeafMode(): void
    {
        $home = $this->tempDir.'/home';
        $this->pmssEnsureFixtureDirectory($home);

        $ok = \pmssEnsureUserHomeDir($this->user, $home, 'www/recycle', 0771, null, 0755);
        $this->assertTrue($ok);
        $this->assertTrue(is_dir($home.'/www'));
        $this->assertTrue(is_dir($home.'/www/recycle'));

        $wwwMode = @fileperms($home.'/www');
        $recycleMode = @fileperms($home.'/www/recycle');
        $this->assertTrue($wwwMode !== false && (($wwwMode & 0777) === 0755), 'Expected parent mode 0755 for www');
        $this->assertTrue($recycleMode !== false && (($recycleMode & 0777) === 0771), 'Expected leaf mode 0771 for recycle');
    }

    public function testRejectsTraversalRelativePath(): void
    {
        $home = $this->tempDir.'/home';
        $this->pmssEnsureFixtureDirectory($home);
        $ok = \pmssEnsureUserHomeDir($this->user, $home, '../evil', 0755);
        $this->assertTrue($ok === false);
        $this->assertTrue(!is_dir($this->tempDir.'/evil'));
    }

    public function testRejectsAbsoluteRelativePath(): void
    {
        $home = $this->tempDir.'/home';
        $this->pmssEnsureFixtureDirectory($home);
        $ok = \pmssEnsureUserHomeDir($this->user, $home, '/etc', 0755);
        $this->assertTrue($ok === false);
    }

    public function testRejectsSymlinkedTarget(): void
    {
        $home = $this->tempDir.'/home';
        $this->pmssEnsureFixtureDirectory($home);
        $target = $home.'/.tmp';
        @symlink($this->tempDir, $target);

        $ok = \pmssEnsureUserHomeDir($this->user, $home, '.tmp', 0755);
        $this->assertTrue($ok === false);
        $this->assertTrue(is_link($target));
    }

    public function testRejectsSymlinkedParentDirectory(): void
    {
        $home = $this->tempDir.'/home';
        $this->pmssEnsureFixtureDirectory($home);

        $elsewhere = $this->tempDir.'/elsewhere';
        $this->pmssEnsureFixtureDirectory($elsewhere, 0700);

        $symlinked = $home.'/.lighttpd';
        @symlink($elsewhere, $symlinked);

        $ok = \pmssEnsureUserHomeDir($this->user, $home, '.lighttpd/custom.d', 0750);
        $this->assertTrue($ok === false);
        $this->assertTrue(is_link($symlinked));
        $this->assertTrue(!is_dir($elsewhere.'/custom.d'), 'must not create directories via symlinked parent');
    }

    public function testConvergesLeafModeWhenDirectoryExists(): void
    {
        $home = $this->tempDir.'/home';
        $this->pmssEnsureFixtureDirectory($home);
        $dir = $home.'/.tmp';
        $this->pmssEnsureFixtureDirectory($dir, 0700);
        @chmod($dir, 0700);

        $ok = \pmssEnsureUserHomeDir($this->user, $home, '.tmp', 0755);
        $this->assertTrue($ok);

        $mode = @fileperms($dir);
        $this->assertTrue($mode !== false && (($mode & 0777) === 0755), 'Expected leaf mode to converge to 0755');
    }
}
