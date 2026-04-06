<?php
namespace {
    pmssTestInstallRunUserStepShim('profile');
}

namespace PMSS\Tests {

require_once dirname(__DIR__, 2).'/update/users.php';

class UserUpdatePluginsTest extends TestCase
{
    public function testEnsurePluginsReportsMissingSource(): void
    {
        $home = $this->pmssMakeTempDir('pmss-plugins-home-');

        $previous = $this->pmssCaptureEnv(['PMSS_DRY_RUN', 'PMSS_SKEL_DIR']);
        putenv('PMSS_DRY_RUN=1');
        putenv('PMSS_SKEL_DIR='.sys_get_temp_dir().'/does-not-exist');

        $GLOBALS['PMSS_PROFILE'] = [];
        try {
            $ctx = [
                'user'     => 'dummy',
                'home'     => $home,
                'user_esc' => escapeshellarg('dummy'),
            ];
            \pmssUserEnsurePlugins($ctx);

            $expectedSource = sys_get_temp_dir().'/does-not-exist/www/rutorrent/plugins/unpack';
            $expectedDest   = $home.'/www/rutorrent/plugins/unpack';
            $expectedCmd = sprintf(
                'cp -Rp %s %s',
                escapeshellarg($expectedSource),
                escapeshellarg($expectedDest)
            );
            $this->assertEquals(
                $expectedCmd,
                (string) $this->pmssFindProfileCommand('Installing unpack plugin')
            );
        } finally {
            $this->pmssRestoreEnvMap($previous);
        }
    }

    public function testEnsurePluginsOwnsRetrackerCleanupAndDirectoryBootstrap(): void
    {
        $home = $this->pmssMakeTempDir('pmss-plugins-retracker-');
        $settingsDir = $home.'/www/rutorrent/share/users/dummy/settings';
        @mkdir($home.'/www/rutorrent/plugins/unpack', 0755, true);
        @mkdir($settingsDir, 0755, true);
        file_put_contents(
            $settingsDir.'/retrackers.dat',
            'O:11:"rRetrackers":4:{s:4:"hash";s:14:"retrackers.dat";s:4:"list";a:1:{i:0;a:1:{i:0;s:33:"http://149.5.241.17:6969/announce";}}s:14:"dontAddPrivate";s:1:"1";s:10:"addToBegin";s:1:"1";}'
        );

        $GLOBALS['PMSS_PROFILE'] = [];

        \pmssUserEnsurePlugins([
            'user'     => 'dummy',
            'home'     => $home,
            'user_esc' => escapeshellarg('dummy'),
        ]);

        $this->assertTrue(!file_exists($settingsDir.'/retrackers.dat'));
        $this->assertEquals(
            sprintf('mkdir -p %s', escapeshellarg($home.'/www/rutorrent/share/users/dummy/torrents')),
            $this->pmssFindProfileCommand('Creating ruTorrent torrents directory')
        );
        $this->assertEquals(
            sprintf('mkdir -p %s', escapeshellarg($home.'/www/rutorrent/share/settings/rss')),
            $this->pmssFindProfileCommand('Creating ruTorrent RSS settings directory')
        );
    }

}

}
