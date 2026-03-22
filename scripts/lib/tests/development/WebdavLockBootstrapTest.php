<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/FilesystemCleanupTrait.php';
require_once dirname(__DIR__, 3).'/util/userConfigLighttpd.php';

class WebdavLockBootstrapTest extends TestCase
{
    use FilesystemCleanupTrait;

    public function testCreatesLockFileWithSafePerms(): void
    {
        $dir = $this->makeTempDir('pmss-webdav-lock');
        $userHome = $dir.'/home/deefbox';
        mkdir($userHome.'/.lighttpd', 0700, true);

        \pmssEnsureWebdavLockDatabase('deefbox', $userHome);

        $lockFile = $userHome.'/.lighttpd/webdav.lock.db';
        $this->assertTrue(is_file($lockFile), 'expected lock file created');
        $this->assertEquals(0600, fileperms($lockFile) & 0777, 'expected 0600 lock perms');

        $this->cleanup($dir);
    }

    public function testFixesLockFilePermissions(): void
    {
        $dir = $this->makeTempDir('pmss-webdav-lock-perms');
        $userHome = $dir.'/home/deefbox';
        mkdir($userHome.'/.lighttpd', 0700, true);

        $lockFile = $userHome.'/.lighttpd/webdav.lock.db';
        file_put_contents($lockFile, '');
        chmod($lockFile, 0644);

        \pmssEnsureWebdavLockDatabase('deefbox', $userHome);

        $this->assertEquals(0600, fileperms($lockFile) & 0777, 'expected perms tightened');

        $this->cleanup($dir);
    }

    public function testSkipsWhenLighttpdDirMissing(): void
    {
        $dir = $this->makeTempDir('pmss-webdav-lock-skip');
        $userHome = $dir.'/home/deefbox';
        mkdir($userHome, 0700, true);

        \pmssEnsureWebdavLockDatabase('deefbox', $userHome);

        $lockFile = $userHome.'/.lighttpd/webdav.lock.db';
        $this->assertTrue(!file_exists($lockFile), 'expected no lock file when .lighttpd missing');

        $this->cleanup($dir);
    }

    private function makeTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        return $dir;
    }

}
