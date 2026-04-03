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

        $ctx = [
            'user'               => 'dummy',
            'home'               => $home,
            'user_esc'           => escapeshellarg('dummy'),
            'rutorrent_index_sha'=> '',
        ];

        $jsonLog = $this->pmssMakeTempFile('pmss-user-theme-');
        @file_put_contents($jsonLog, '');

        $previous = $this->pmssCaptureEnv(['PMSS_DRY_RUN', 'PMSS_JSON_LOG', 'PMSS_SKEL_DIR']);
        putenv('PMSS_DRY_RUN=1');
        putenv('PMSS_JSON_LOG='.$jsonLog);
        putenv('PMSS_SKEL_DIR='.$skel);
        $GLOBALS['PMSS_JSON_LOG_PATH'] = null;

        $cmd = null;
        try {
            \pmssUserUpdateThemes($ctx);
            $cmd = $this->pmssFindJsonStepCommand($jsonLog, 'Installing ruTorrent theme Agent34');
        } finally {
            $this->pmssRestoreEnvMap($previous);
            $GLOBALS['PMSS_JSON_LOG_PATH'] = null;
        }

        $expected = sprintf(
            'cp -r %s %s',
            escapeshellarg($skel.'/www/rutorrent/plugins/theme/themes/Agent34'),
            escapeshellarg($home.'/www/rutorrent/plugins/theme/themes/')
        );
        $this->assertEquals($expected, $cmd ?? '');
    }

    private function findStepCommand(string $jsonLog, string $needle): ?string
    {
        return $this->pmssFindJsonStepCommand($jsonLog, $needle);
    }
}
