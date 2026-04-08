<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/passwords.php';

class userPasswordShadowSyncTest extends TestCase
{
    private $tempDir = '';

    protected function setUp(): void
    {
        $this->pmssAssignTempDirProperty('tempDir', 'pmss-user-password-shadow-sync-');
    }

    protected function tearDown(): void
    {
        $this->pmssCleanupTempDirProperty('tempDir');
    }

    public function testReadShadowPasswordHashReturnsEntryForManagedUser(): void
    {
        $shadowPath = $this->tempDir.'/shadow';
        file_put_contents($shadowPath, 'alice:$6$hash$abc:20000:0:99999:7:::'."\n");

        $this->assertEquals('$6$hash$abc', \pmssUserShadowPasswordHashRead('alice', $shadowPath));
    }

    public function testReadShadowPasswordHashReturnsEmptyForLockedEntry(): void
    {
        $shadowPath = $this->tempDir.'/shadow';
        file_put_contents($shadowPath, 'alice:!$6$hash$abc:20000:0:99999:7:::'."\n");

        $this->assertEquals('', \pmssUserShadowPasswordHashRead('alice', $shadowPath));
    }

    public function testReadShadowPasswordHashReturnsEmptyForMissingUser(): void
    {
        $shadowPath = $this->tempDir.'/shadow';
        file_put_contents($shadowPath, 'bob:$6$hash$abc:20000:0:99999:7:::'."\n");

        $this->assertEquals('', \pmssUserShadowPasswordHashRead('alice', $shadowPath));
    }

    public function testHtpasswdHashWriteReplacesDuplicateUserEntries(): void
    {
        $path = $this->tempDir.'/home/alice/.lighttpd/.htpasswd';
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, "alice:old-hash\nbob:keep-me\nalice:older-hash\n");

        $this->assertTrue(\pmssUserHtpasswdHashWrite($path, 'alice', '$6$new$hash', $this->pmssCurrentOwner()));
        $contents = (string) file_get_contents($path);

        $this->assertEquals('alice:$6$new$hash'."\n".'bob:keep-me'."\n", $contents);
    }

    public function testHtpasswdHashWriteRejectsTraversalTarget(): void
    {
        $managedDir = $this->tempDir.'/home/alice/.lighttpd';
        $outsideDir = $this->tempDir.'/home/alice/outside';
        @mkdir($managedDir, 0755, true);
        @mkdir($outsideDir, 0755, true);

        $this->assertFalse(\pmssUserHtpasswdHashWrite($managedDir.'/../outside/.htpasswd', 'alice', '$6$new$hash', $this->pmssCurrentOwner()));
        $this->assertFalse(file_exists($outsideDir.'/.htpasswd'));
    }

    public function testHtpasswdSyncFromShadowWritesUnlockedHash(): void
    {
        $homeRoot = $this->pmssTrackHomeRoot($this->tempDir.'/home');
        $shadowPath = $this->tempDir.'/shadow';
        $htpasswdPath = $homeRoot.'/alice/.lighttpd/.htpasswd';
        @mkdir(dirname($htpasswdPath), 0755, true);
        file_put_contents($shadowPath, 'alice:$6$shadow$hash:20000:0:99999:7:::'."\n");
        file_put_contents($htpasswdPath, 'alice:$apr1$legacy$hash'."\n");

        $this->assertTrue(\pmssUserHtpasswdSyncFromShadow('alice', $shadowPath));
        $this->assertEquals('alice:$6$shadow$hash'."\n", (string) file_get_contents($htpasswdPath));
    }

    public function testUnsuspendRequiresPasswordSyncLibraryAndHook(): void
    {
        $source = $this->pmssReadRepoFile('scripts/unsuspend.php');

        $requirePos = strpos($source, "require_once __DIR__.'/lib/user/passwords.php';");
        $unlockPos = strpos($source, "'unlock_account'");
        $syncPos = strpos($source, 'pmssUserHtpasswdSyncFromShadow($username)');
        $startRtorrentPos = strpos($source, "'start_rtorrent'");

        $this->assertTrue($requirePos !== false, 'unsuspend.php must load the password sync helper');
        $this->assertTrue($unlockPos !== false, 'unsuspend.php must still unlock the Unix account');
        $this->assertTrue($syncPos !== false, 'unsuspend.php must resync per-user htpasswd from shadow');
        $this->assertTrue($startRtorrentPos !== false, 'unsuspend.php must still restart rTorrent');
        $this->assertTrue($unlockPos < $syncPos, 'password resync must run after account unlock');
        $this->assertTrue($syncPos < $startRtorrentPos, 'password resync must finish before service restart');
        $this->assertStringContainsString('Unable to resync per-user htpasswd from unlocked shadow hash', $source);
    }
}
