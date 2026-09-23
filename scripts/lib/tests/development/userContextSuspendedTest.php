<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/update/users.php';

/**
 * Verify pmssBuildUserContext() skips suspended users (www-disabled marker).
 *
 * These tests are hermetic: they point PMSS_HOME_DIR at a temp tree and never
 * touch real /home.
 */
class UserContextSuspendedTest extends TestCase
{
    public function testUserContextIdentityNormalizesBoundaryInputs(): void
    {
        $cases = [
            [['user' => 'testuser', 'home' => '/home/testuser'], ['testuser', '/home/testuser']],
            [['user' => 'testuser', 'home' => '/home/testuser/'], ['testuser', '/home/testuser']],
            [['user' => 123, 'home' => 456], ['123', '456']],
            [['user' => null, 'home' => null], ['', '']],
            [[], ['', '']],
        ];
        foreach ($cases as $case) {
            $this->assertEquals($case[1], \pmssUserContextIdentity($case[0]));
        }
    }

    public function testBuildUserContextSkipsSuspendedUsers(): void
    {
        $user = 'testuser';

        $home = $this->pmssMakeTrackedUserHomeTree('pmss-user-context-suspended-marker-', $user, 'data');
        $this->pmssEnsureDir($home.'/www-disabled');
        $this->pmssWriteFile($home.'/.rtorrent.rc', "dummy");

        $this->assertEquals(null, \pmssBuildUserContext($user));
    }

    public function testBuildUserContextReturnsNullWhenHomeMissing(): void
    {
        $this->pmssMakeTrackedHomeRoot('pmss-user-context-missing-home-');

        $this->assertEquals(null, \pmssBuildUserContext('missinguser'));
    }

    public function testBuildUserContextReturnsNullWhenRtorrentConfigMissing(): void
    {
        $user = 'testuser';

        $this->pmssMakeTrackedUserHomeTree('pmss-user-context-missing-rtorrent-', $user, 'data');

        $this->assertEquals(null, \pmssBuildUserContext($user));
    }

    public function testBuildUserContextReturnsWhenDataDirMissing(): void
    {
        $user = 'testuser';
        $sha = 'sha123';

        $home = $this->pmssMakeTrackedUserHomeTree('pmss-user-context-missing-data-', $user);
        $this->pmssWriteFile($home.'/.rtorrent.rc', "dummy");

        $ctx = \pmssBuildUserContext($user, $sha);
        $this->assertTrue(is_array($ctx));
        $this->assertEquals($user, $ctx['user']);
        $this->assertEquals($home, $ctx['home']);
        $this->assertEquals($sha, $ctx['rutorrent_index_sha']);
    }

    public function testBuildUserContextReturnsWhenMarkerMissing(): void
    {
        $user = 'testuser';
        $sha = 'sha123';

        $home = $this->pmssMakeTrackedUserHomeTree('pmss-user-context-active-', $user, 'data');
        $this->pmssEnsureDir($home.'/www');
        $this->pmssWriteFile($home.'/.rtorrent.rc', "dummy");

        $ctx = \pmssBuildUserContext($user, $sha);
        $this->assertTrue(is_array($ctx));
        $this->assertEquals($user, $ctx['user']);
        $this->assertEquals($home, $ctx['home']);
        $this->assertEquals($sha, $ctx['rutorrent_index_sha']);
    }
}
