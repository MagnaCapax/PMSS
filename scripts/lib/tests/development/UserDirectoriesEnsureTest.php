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
        $this->tempDir = sys_get_temp_dir().'/pmss-user-dir-ensure-'.getmypid();
        @mkdir($this->tempDir, 0700, true);

        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $pw = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : null;
        $this->user = (is_array($pw) && isset($pw['name']) && is_string($pw['name'])) ? $pw['name'] : get_current_user();
    }

    protected function tearDown(): void
    {
        if ($this->tempDir && is_dir($this->tempDir)) {
            $this->recursiveDelete($this->tempDir);
        }
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path) && !is_link($path)) {
                $this->recursiveDelete($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testCreatesNestedPathWithParentModeAndLeafMode(): void
    {
        $home = $this->tempDir.'/home';
        $this->assertTrue(@mkdir($home, 0755, true) || is_dir($home));

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
        $this->assertTrue(@mkdir($home, 0755, true) || is_dir($home));
        $ok = \pmssEnsureUserHomeDir($this->user, $home, '../evil', 0755);
        $this->assertTrue($ok === false);
        $this->assertTrue(!is_dir($this->tempDir.'/evil'));
    }

    public function testRejectsAbsoluteRelativePath(): void
    {
        $home = $this->tempDir.'/home';
        $this->assertTrue(@mkdir($home, 0755, true) || is_dir($home));
        $ok = \pmssEnsureUserHomeDir($this->user, $home, '/etc', 0755);
        $this->assertTrue($ok === false);
    }

    public function testRejectsSymlinkedTarget(): void
    {
        $home = $this->tempDir.'/home';
        $this->assertTrue(@mkdir($home, 0755, true) || is_dir($home));
        $target = $home.'/.tmp';
        @symlink($this->tempDir, $target);

        $ok = \pmssEnsureUserHomeDir($this->user, $home, '.tmp', 0755);
        $this->assertTrue($ok === false);
        $this->assertTrue(is_link($target));
    }

    public function testConvergesLeafModeWhenDirectoryExists(): void
    {
        $home = $this->tempDir.'/home';
        $this->assertTrue(@mkdir($home, 0755, true) || is_dir($home));
        $dir = $home.'/.tmp';
        $this->assertTrue(@mkdir($dir, 0700, true) || is_dir($dir));
        @chmod($dir, 0700);

        $ok = \pmssEnsureUserHomeDir($this->user, $home, '.tmp', 0755);
        $this->assertTrue($ok);

        $mode = @fileperms($dir);
        $this->assertTrue($mode !== false && (($mode & 0777) === 0755), 'Expected leaf mode to converge to 0755');
    }
}

