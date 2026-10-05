<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 3).'/util/userConfigLighttpd.php';

class WebdavLockBootstrapTest extends TestCase
{
    public function testCreatesLockFileWithSafePerms(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-webdav-lock-', 0700);
        $userHome = dirname($this->pmssEnsureDir($dir.'/home/deefbox/.lighttpd', 0700));

        \pmssEnsureWebdavLockDatabase('deefbox', $userHome);

        $lockFile = $userHome.'/.lighttpd/webdav.lock.db';
        $this->assertTrue(is_file($lockFile), 'expected lock file created');
        $this->assertEquals(0600, fileperms($lockFile) & 0777, 'expected 0600 lock perms');
        $this->pmssAssertRepoFileContainsString('scripts/lib/lighttpd/userDirectoriesPrepare.php',
            "pmssWriteUserFile(\$lockFile, '', \$user, 0600)");
    }

    public function testExistingLinkedLockLeavesDestinationUntouched(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-webdav-lock-link-', 0700);
        $userHome = dirname($this->pmssEnsureDir($dir.'/home/deefbox/.lighttpd', 0700));
        $target = $this->pmssWriteFile($dir.'/target', 'keep');
        $lockFile = $userHome.'/.lighttpd/webdav.lock.db';
        $this->assertTrue(symlink($target, $lockFile));

        \pmssEnsureWebdavLockDatabase('deefbox', $userHome);

        $this->assertSame('keep', (string) file_get_contents($target));
        $this->assertTrue(is_link($lockFile));
    }

    public function testFixesLockFilePermissions(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-webdav-lock-perms-', 0700);
        $userHome = dirname($this->pmssEnsureDir($dir.'/home/deefbox/.lighttpd', 0700));

        $lockFile = $userHome.'/.lighttpd/webdav.lock.db';
        file_put_contents($lockFile, '');
        chmod($lockFile, 0644);

        \pmssEnsureWebdavLockDatabase('deefbox', $userHome);

        $this->assertEquals(0600, fileperms($lockFile) & 0777, 'expected perms tightened');
    }

    public function testSkipsWhenLighttpdDirMissing(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-webdav-lock-skip-', 0700);
        $userHome = $this->pmssEnsureDir($dir.'/home/deefbox', 0700);

        \pmssEnsureWebdavLockDatabase('deefbox', $userHome);

        $lockFile = $userHome.'/.lighttpd/webdav.lock.db';
        $this->assertTrue(!file_exists($lockFile), 'expected no lock file when .lighttpd missing');
    }
}
