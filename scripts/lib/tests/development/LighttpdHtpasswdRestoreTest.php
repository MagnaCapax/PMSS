<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/lighttpd/htpasswd.php';

/** A missing per-user .htpasswd is rebuilt from the shadow hash; nothing else is touched. */
class LighttpdHtpasswdRestoreTest extends TestCase
{
    protected function pmssTempDirFixtureArguments(): array { return ['tempDir', 'pmss-htpasswd-restore-']; }

    /** Home with a .lighttpd directory, plus a shadow file holding $hashField for the current account. */
    private function fixture(string $hashField, bool $withLighttpdDir = true): array
    {
        $user = $this->pmssCurrentOwner();
        $home = $this->tempDir.'/home/'.$user;
        @mkdir($withLighttpdDir ? $home.'/.lighttpd' : $home, 0755, true);
        $shadow = $this->tempDir.'/shadow';
        file_put_contents($shadow, $user.':'.$hashField.':20000:0:99999:7:::'."\n");

        return [$user, $home, $shadow];
    }

    public function testMissingFileIsRestoredFromShadowHash(): void
    {
        [$user, $home, $shadow] = $this->fixture('$6$shadow$hash');

        $this->assertTrue(\pmssUserHtpasswdRestoreIfMissing($user, $home, $shadow));
        $this->assertSame($user.':$6$shadow$hash'."\n", (string) file_get_contents($home.'/.lighttpd/.htpasswd'));
        $this->assertSame(0640, fileperms($home.'/.lighttpd/.htpasswd') & 0777);
    }

    public function testSecondPassLeavesTheRestoredFileAlone(): void
    {
        [$user, $home, $shadow] = $this->fixture('$6$shadow$hash');
        $this->assertTrue(\pmssUserHtpasswdRestoreIfMissing($user, $home, $shadow));
        $inode = fileinode($home.'/.lighttpd/.htpasswd');

        $this->assertFalse(\pmssUserHtpasswdRestoreIfMissing($user, $home, $shadow));
        clearstatcache(true, $home.'/.lighttpd/.htpasswd');
        $this->assertSame($inode, fileinode($home.'/.lighttpd/.htpasswd'));
    }

    public function testExistingFileIsNeverRewritten(): void
    {
        [$user, $home, $shadow] = $this->fixture('$6$shadow$hash');
        file_put_contents($home.'/.lighttpd/.htpasswd', $user.':customer-chosen'."\n");

        $this->assertFalse(\pmssUserHtpasswdRestoreIfMissing($user, $home, $shadow));
        $this->assertSame($user.':customer-chosen'."\n", (string) file_get_contents($home.'/.lighttpd/.htpasswd'));
    }

    public function testEmptyExistingFileIsStillAnExistingFile(): void
    {
        [$user, $home, $shadow] = $this->fixture('$6$shadow$hash');
        file_put_contents($home.'/.lighttpd/.htpasswd', '');

        $this->assertFalse(\pmssUserHtpasswdRestoreIfMissing($user, $home, $shadow));
        $this->assertSame('', (string) file_get_contents($home.'/.lighttpd/.htpasswd'));
    }

    public function testUnusableShadowHashesWriteNothing(): void
    {
        foreach (['', '!', '*', '!$6$locked$hash'] as $hashField) {
            [$user, $home, $shadow] = $this->fixture($hashField);

            $this->assertFalse(\pmssUserHtpasswdRestoreIfMissing($user, $home, $shadow), 'hash field: '.$hashField);
            $this->assertFalse(file_exists($home.'/.lighttpd/.htpasswd'), 'hash field: '.$hashField);
        }
    }

    public function testUserMissingFromShadowWritesNothing(): void
    {
        [$user, $home, $shadow] = $this->fixture('$6$shadow$hash');
        file_put_contents($shadow, "someoneelse:\$6\$x\$y:20000:0:99999:7:::\n");

        $this->assertFalse(\pmssUserHtpasswdRestoreIfMissing($user, $home, $shadow));
        $this->assertFalse(file_exists($home.'/.lighttpd/.htpasswd'));
    }

    public function testMissingLighttpdDirectoryIsNotCreated(): void
    {
        [$user, $home, $shadow] = $this->fixture('$6$shadow$hash', false);

        $this->assertFalse(\pmssUserHtpasswdRestoreIfMissing($user, $home, $shadow));
        $this->assertFalse(file_exists($home.'/.lighttpd'));
    }

    public function testDanglingLinkAtTheTargetIsNotFollowedOrReplaced(): void
    {
        [$user, $home, $shadow] = $this->fixture('$6$shadow$hash');
        $elsewhere = $this->tempDir.'/elsewhere/.htpasswd';
        $this->pmssCreateSymlinkOrSkip($elsewhere, $home.'/.lighttpd/.htpasswd');

        $this->assertFalse(\pmssUserHtpasswdRestoreIfMissing($user, $home, $shadow));
        $this->assertFalse(file_exists($elsewhere));
        $this->assertTrue(is_link($home.'/.lighttpd/.htpasswd'));
    }

    public function testSymlinkedLighttpdDirectoryIsRefused(): void
    {
        [$user, $home, $shadow] = $this->fixture('$6$shadow$hash', false);
        $elsewhere = $this->tempDir.'/elsewhere';
        @mkdir($elsewhere, 0755, true);
        $this->pmssCreateSymlinkOrSkip($elsewhere, $home.'/.lighttpd');

        $this->assertFalse(\pmssUserHtpasswdRestoreIfMissing($user, $home, $shadow));
        $this->assertFalse(file_exists($elsewhere.'/.htpasswd'));
    }

    public function testWatchdogRestoresTheFileOnEveryPassAfterConfigRegeneration(): void
    {
        $source = $this->pmssReadRepoFile('scripts/cron/checkLighttpdInstances.php');
        $this->assertStringContainsString("require_once __DIR__.'/../lib/lighttpd/htpasswd.php';", $source);
        $this->assertOrderedStrings([
            "pmssUserWatchdogBackoffActive(\$thisUser, 'lighttpd')) continue;",
            'lighttpd config generated (missing config detected)',
            'pmssUserHtpasswdRestoreIfMissing($thisUser, $homeDir)',
            '$dockerProxyRefresh = ',
        ], $source);
    }
}
