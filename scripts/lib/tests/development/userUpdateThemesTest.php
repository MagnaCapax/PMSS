<?php
namespace PMSS\Tests;

require_once dirname(__DIR__, 2).'/update/users.php';

class UserUpdateThemesTest extends TestCase
{
    public function testUpdateThemesUsesSkelOverrideForMissingTheme(): void
    {
        $home = $this->pmssMakeTempDir('pmss-user-theme-home-');
        $skel = $this->pmssMakeTempDir('pmss-user-theme-skel-');
        @mkdir($home.'/www/rutorrent/plugins/theme/themes', 0755, true);
        @mkdir($skel.'/www/rutorrent/plugins/theme/themes', 0755, true);

        $ctx = $this->pmssUserUpdateContext($home, 'dummy', ['rutorrent_index_sha' => '']);

        $jsonLog = $this->pmssMakeTempFile('pmss-user-theme-');
        @file_put_contents($jsonLog, '');

        $cmd = null;
        try {
            $GLOBALS['PMSS_JSON_LOG_PATH'] = null;
            $this->pmssWithTrackedEnv([
                'PMSS_DRY_RUN' => '1',
                'PMSS_JSON_LOG' => $jsonLog,
                'PMSS_SKEL_DIR' => $skel,
            ], function () use ($ctx, $jsonLog, &$cmd): void {
                \pmssUserUpdateThemes($ctx);
                $cmd = $this->pmssFindJsonStepCommand($jsonLog, 'Installing ruTorrent theme Agent34');
            });
        } finally {
            $GLOBALS['PMSS_JSON_LOG_PATH'] = null;
        }

        $expected = sprintf(
            'cp -r %s %s',
            escapeshellarg($skel.'/www/rutorrent/plugins/theme/themes/Agent34'),
            escapeshellarg($home.'/www/rutorrent/plugins/theme/themes/')
        );
        $this->assertEquals($expected, $cmd ?? '');
    }
}
