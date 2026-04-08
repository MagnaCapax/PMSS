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
    public function testBuildUserContextSkipsSuspendedUsers(): void
    {
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-user-context-suspended-marker-');
        $user = 'testuser';

        $home = $homeRoot.'/'.$user;
        @mkdir($home.'/data', 0755, true);
        @mkdir($home.'/www-disabled', 0755, true);
        file_put_contents($home.'/.rtorrent.rc', "dummy");

        $this->assertEquals(null, \pmssBuildUserContext($user));
    }

    public function testBuildUserContextReturnsNullWhenHomeMissing(): void
    {
        $this->pmssMakeTrackedHomeRoot('pmss-user-context-missing-home-');

        $this->assertEquals(null, \pmssBuildUserContext('missinguser'));
    }

    public function testBuildUserContextReturnsNullWhenRtorrentConfigMissing(): void
    {
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-user-context-missing-rtorrent-');
        $user = 'testuser';

        $home = $homeRoot.'/'.$user;
        @mkdir($home.'/data', 0755, true);

        $this->assertEquals(null, \pmssBuildUserContext($user));
    }

    public function testBuildUserContextReturnsNullWhenDataDirMissing(): void
    {
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-user-context-missing-data-');
        $user = 'testuser';

        $home = $homeRoot.'/'.$user;
        @mkdir($home, 0755, true);
        file_put_contents($home.'/.rtorrent.rc', "dummy");

        $this->assertEquals(null, \pmssBuildUserContext($user));
    }

    public function testBuildUserContextReturnsWhenMarkerMissing(): void
    {
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-user-context-active-');
        $user = 'testuser';
        $sha = 'sha123';

        $home = $homeRoot.'/'.$user;
        @mkdir($home.'/data', 0755, true);
        @mkdir($home.'/www', 0755, true);
        file_put_contents($home.'/.rtorrent.rc', "dummy");

        $ctx = \pmssBuildUserContext($user, $sha);
        $this->assertTrue(is_array($ctx));
        $this->assertEquals($user, $ctx['user']);
        $this->assertEquals($home, $ctx['home']);
        $this->assertEquals($sha, $ctx['rutorrent_index_sha']);
    }
}
