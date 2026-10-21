<?php
namespace {
    pmssTestInstallRunUserStepShim('profile');
}

namespace PMSS\Tests {

require_once dirname(__DIR__, 2).'/update/users.php';

class UserUpdatePluginsTest extends TestCase
{
    public function testDeprecatedPluginRemovalUsesAccountCommand(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/update/users/rutorrent.php');
        $this->assertStringContainsString("pmssRutorrentAccountStep(\$user, \$home, 'Removing deprecated cpuload plugin'", $source);
        $this->assertFalse(strpos($source, "sprintf('rm -rf %s'") !== false);
    }

    public function testEnsurePluginsReportsMissingSource(): void
    {
        $home = $this->pmssMakeTempDir('pmss-plugins-home-');

        $GLOBALS['PMSS_PROFILE'] = [];
        $this->pmssWithEnv([
            'PMSS_DRY_RUN' => '1',
            'PMSS_SKEL_DIR' => sys_get_temp_dir().'/does-not-exist',
        ], function () use ($home): void {
            $ctx = $this->pmssUserUpdateContext($home);
            \pmssUserEnsurePlugins($ctx);

            $this->assertSame(null, $this->pmssFindProfileCommand('Installing unpack plugin'));
            $this->assertFalse(file_exists($home.'/www/rutorrent/plugins/unpack'));
        });
    }

    public function testEnsurePluginsOwnsRetrackerCleanupAndDirectoryBootstrap(): void
    {
        $user = $this->pmssCurrentOwner();
        $root = $this->pmssMakeTrackedHomeRoot('pmss-plugins-retracker-');
        $home = $this->pmssEnsureDir($root.'/'.$user);
        $settingsDir = $home.'/www/rutorrent/share/users/'.$user.'/settings';
        @mkdir($home.'/www/rutorrent/plugins/unpack', 0755, true);
        @mkdir($settingsDir, 0755, true);
        file_put_contents(
            $settingsDir.'/retrackers.dat',
            'O:11:"rRetrackers":4:{s:4:"hash";s:14:"retrackers.dat";s:4:"list";a:1:{i:0;a:1:{i:0;s:33:"http://149.5.241.17:6969/announce";}}s:14:"dontAddPrivate";s:1:"1";s:10:"addToBegin";s:1:"1";}'
        );

        $GLOBALS['PMSS_PROFILE'] = [];

        \pmssUserEnsurePlugins($this->pmssUserUpdateContext($home, $user));

        $this->assertTrue(!file_exists($settingsDir.'/retrackers.dat'));
        $this->assertTrue(is_dir($home.'/www/rutorrent/share/users/'.$user.'/torrents'));
        $this->assertTrue(is_dir($home.'/www/rutorrent/share/settings/rss'));
    }

}

}
