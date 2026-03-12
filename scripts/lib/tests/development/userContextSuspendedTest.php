<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 3).'/lib/update/user/context.php';

/**
 * Verify pmssBuildUserContext() skips suspended users (www-disabled marker).
 *
 * These tests are hermetic: they point PMSS_HOME_DIR at a temp tree and never
 * touch real /home.
 */
class UserContextSuspendedTest extends TestCase
{
    public function tearDown(): void
    {
        putenv('PMSS_HOME_DIR');
    }

    public function testBuildUserContextSkipsSuspendedUsers(): void
    {
        $base = sys_get_temp_dir().'/pmss-user-context-suspended-marker-'.uniqid('', true);
        $homeRoot = $base.'/home';
        $user = 'testuser';

        putenv('PMSS_HOME_DIR='.$homeRoot);

        $home = $homeRoot.'/'.$user;
        @mkdir($home.'/data', 0755, true);
        @mkdir($home.'/www-disabled', 0755, true);
        file_put_contents($home.'/.rtorrent.rc', "dummy");

        $this->assertEquals(null, \pmssBuildUserContext($user));
    }

    public function testBuildUserContextReturnsNullWhenHomeMissing(): void
    {
        $base = sys_get_temp_dir().'/pmss-user-context-missing-home-'.uniqid('', true);
        $homeRoot = $base.'/home';

        putenv('PMSS_HOME_DIR='.$homeRoot);

        $this->assertEquals(null, \pmssBuildUserContext('missinguser'));
    }

    public function testBuildUserContextReturnsNullWhenRtorrentConfigMissing(): void
    {
        $base = sys_get_temp_dir().'/pmss-user-context-missing-rtorrent-'.uniqid('', true);
        $homeRoot = $base.'/home';
        $user = 'testuser';

        putenv('PMSS_HOME_DIR='.$homeRoot);

        $home = $homeRoot.'/'.$user;
        @mkdir($home.'/data', 0755, true);

        $this->assertEquals(null, \pmssBuildUserContext($user));
    }

    public function testBuildUserContextReturnsNullWhenDataDirMissing(): void
    {
        $base = sys_get_temp_dir().'/pmss-user-context-missing-data-'.uniqid('', true);
        $homeRoot = $base.'/home';
        $user = 'testuser';

        putenv('PMSS_HOME_DIR='.$homeRoot);

        $home = $homeRoot.'/'.$user;
        @mkdir($home, 0755, true);
        file_put_contents($home.'/.rtorrent.rc', "dummy");

        $this->assertEquals(null, \pmssBuildUserContext($user));
    }

    public function testBuildUserContextReturnsWhenMarkerMissing(): void
    {
        $base = sys_get_temp_dir().'/pmss-user-context-active-'.uniqid('', true);
        $homeRoot = $base.'/home';
        $user = 'testuser';
        $sha = 'sha123';

        putenv('PMSS_HOME_DIR='.$homeRoot);

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
