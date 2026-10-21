<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/update/users.php';

class UserUpdateThemesTest extends TestCase
{
    public function testUpdateThemesUsesSkelOverrideForMissingTheme(): void
    {
        $user = $this->pmssCurrentOwner();
        $homeRoot = $this->pmssMakeTrackedHomeRoot('pmss-user-theme-home-');
        $home = $this->pmssEnsureDir($homeRoot.'/'.$user);
        $skel = $this->pmssMakeTempDir('pmss-user-theme-skel-');
        @mkdir($home.'/www/rutorrent/plugins/theme/themes', 0755, true);
        @mkdir($skel.'/www/rutorrent/plugins/theme/themes', 0755, true);
        $source = $skel.'/www/rutorrent/plugins/theme/themes/Agent34';
        $this->pmssWriteFile($source.'/style.css', 'theme');

        $ctx = $this->pmssUserUpdateContext($home, $user, ['rutorrent_index_sha' => '']);
        $this->pmssWithTrackedEnv(['PMSS_SKEL_DIR' => $skel], function () use ($ctx): void {
            \pmssUserUpdateThemes($ctx);
        });
        $target = $home.'/www/rutorrent/plugins/theme/themes/Agent34/style.css';
        $this->assertSame('theme', file_get_contents($target));
        $this->assertSame(posix_geteuid(), fileowner($target));
    }
}
