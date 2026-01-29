<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/util/userConfigLighttpd.php';

class LighttpdUserDirectoryPrepTest extends TestCase
{
    private $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir().'/pmss-lighttpd-dirprep-'.uniqid('', true);
        @mkdir($this->base, 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->base)) {
            $this->recursiveDelete($this->base);
        }
    }

    private function recursiveDelete(string $dir): void
    {
        $items = @scandir($dir);
        if (!is_array($items)) {
            return;
        }
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

    public function testCreatesDirectoriesAndCustomFile(): void
    {
        $user = 'testuser';
        $home = $this->base.'/home';
        $this->assertTrue(@mkdir($home, 0755, true) || is_dir($home));
        $this->assertTrue(@mkdir($home.'/www', 0755, true) || is_dir($home.'/www'));

        $ok = \pmssPrepareLighttpdUserDirectories($user, $home, true);
        $this->assertTrue($ok);

        $this->assertTrue(is_dir($home.'/.lighttpd'));
        $this->assertTrue(is_dir($home.'/.lighttpd/custom.d'));
        $this->assertTrue(is_dir($home.'/.lighttpd/upload'));
        $this->assertTrue(is_dir($home.'/.lighttpd/compress'));
        $this->assertTrue(is_dir($home.'/www/public'));
        $this->assertTrue(is_file($home.'/.lighttpd/custom'));

        $this->assertEquals(0751, (@fileperms($home.'/.lighttpd') & 0777));
        $this->assertEquals(0750, (@fileperms($home.'/.lighttpd/custom.d') & 0777));
        $this->assertEquals(0751, (@fileperms($home.'/.lighttpd/upload') & 0777));
        $this->assertEquals(0751, (@fileperms($home.'/.lighttpd/compress') & 0777));
        $this->assertEquals(0751, (@fileperms($home.'/www/public') & 0777));
    }

    public function testRejectsInvalidUsername(): void
    {
        $home = $this->base.'/home';
        $this->assertTrue(@mkdir($home, 0755, true) || is_dir($home));
        $ok = \pmssPrepareLighttpdUserDirectories('bad/user', $home, false);
        $this->assertTrue($ok === false);
    }

    public function testRejectsSymlinkedLighttpdDir(): void
    {
        $user = 'testuser';
        $home = $this->base.'/home';
        $this->assertTrue(@mkdir($home, 0755, true) || is_dir($home));
        $this->assertTrue(@mkdir($home.'/www', 0755, true) || is_dir($home.'/www'));

        $elsewhere = $this->base.'/elsewhere';
        $this->assertTrue(@mkdir($elsewhere, 0700, true) || is_dir($elsewhere));
        @symlink($elsewhere, $home.'/.lighttpd');

        $ok = \pmssPrepareLighttpdUserDirectories($user, $home, true);
        $this->assertTrue($ok === false);
        $this->assertTrue(is_link($home.'/.lighttpd'));
        $this->assertTrue(!is_dir($elsewhere.'/custom.d'));
    }
}

