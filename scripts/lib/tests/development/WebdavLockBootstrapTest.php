<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/util/userConfigLighttpd.php';

class WebdavLockBootstrapTest extends TestCase
{
    public function testCreatesLockFileWithSafePerms(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-webdav-lock-', 0700);
        $userHome = $dir.'/home/deefbox';
        mkdir($userHome.'/.lighttpd', 0700, true);

        \pmssEnsureWebdavLockDatabase('deefbox', $userHome);

        $lockFile = $userHome.'/.lighttpd/webdav.lock.db';
        $this->assertTrue(is_file($lockFile), 'expected lock file created');
        $this->assertEquals(0600, fileperms($lockFile) & 0777, 'expected 0600 lock perms');
    }

    public function testFixesLockFilePermissions(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-webdav-lock-perms-', 0700);
        $userHome = $dir.'/home/deefbox';
        mkdir($userHome.'/.lighttpd', 0700, true);

        $lockFile = $userHome.'/.lighttpd/webdav.lock.db';
        file_put_contents($lockFile, '');
        chmod($lockFile, 0644);

        \pmssEnsureWebdavLockDatabase('deefbox', $userHome);

        $this->assertEquals(0600, fileperms($lockFile) & 0777, 'expected perms tightened');
    }

    public function testSkipsWhenLighttpdDirMissing(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-webdav-lock-skip-', 0700);
        $userHome = $dir.'/home/deefbox';
        mkdir($userHome, 0700, true);

        \pmssEnsureWebdavLockDatabase('deefbox', $userHome);

        $lockFile = $userHome.'/.lighttpd/webdav.lock.db';
        $this->assertTrue(!file_exists($lockFile), 'expected no lock file when .lighttpd missing');
    }
}
