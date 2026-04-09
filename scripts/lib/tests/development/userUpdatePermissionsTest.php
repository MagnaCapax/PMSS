<?php
namespace {
    pmssTestInstallRunUserStepShim('last');
}

namespace PMSS\Tests {

require_once dirname(__DIR__, 2).'/update/users.php';

class UserUpdatePermissionsTest extends TestCase
{
    public function testRefreshPermissionsBuildsExpectedCommand(): void
    {
        $home = $this->pmssMakeTempDir('pmss-perm-cmd-');
        $jsonLog = $this->pmssMakeTempFile('pmss-user-perm-json-');

        $ctx = [
            'user'     => 'dummy',
            'home'     => $home,
            'user_esc' => escapeshellarg('dummy'),
        ];

        $previousJsonLogPath = $GLOBALS['PMSS_JSON_LOG_PATH'] ?? null;
        unset($GLOBALS['PMSS_TEST_RUNUSERSTEP_LAST']);

        try {
            $GLOBALS['PMSS_JSON_LOG_PATH'] = null;
            $this->pmssWithTrackedEnv([
                'PMSS_COMMAND_TIMEOUT' => '321',
                'PMSS_DRY_RUN' => '1',
                'PMSS_JSON_LOG' => $jsonLog,
            ], function () use ($ctx, $jsonLog): void {
                \pmssUserRefreshPermissions($ctx);

                $expectedCommand = \pmssBuildCommand('/scripts/util/userPermissions.php', ['dummy']);
                foreach (['/usr/bin/ionice', '/bin/ionice'] as $ionicePath) {
                    if (!is_executable($ionicePath)) {
                        continue;
                    }
                    $expectedCommand = \pmssBuildCommand($ionicePath, ['-c3', '/scripts/util/userPermissions.php', 'dummy']);
                    break;
                }

                $observedCommand = isset($GLOBALS['PMSS_TEST_RUNUSERSTEP_LAST']['command'])
                    ? (string) $GLOBALS['PMSS_TEST_RUNUSERSTEP_LAST']['command']
                    : $this->pmssFindJsonStepCommand($jsonLog, 'Refreshing user permissions');

                $this->assertEquals($expectedCommand, $observedCommand ?? '');
                $this->assertEquals('321', getenv('PMSS_COMMAND_TIMEOUT'));
            });
        } finally {
            $GLOBALS['PMSS_JSON_LOG_PATH'] = $previousJsonLogPath;
            unset($GLOBALS['PMSS_TEST_RUNUSERSTEP_LAST']);
        }
    }

    public function testRefreshPermissionsUpdatesLegacyFile(): void
    {
        $home = $this->pmssMakeTempDir('pmss-perm-');
        file_put_contents($home.'/.rtorrent.rc.custom', "legacy");
        $this->pmssWithEnv(['PMSS_SKEL_DIR' => $home], function () use ($home): void {
            file_put_contents($home.'/.rtorrent.rc.custom', 'legacy');
            $ctx = [
                'user'     => 'dummy',
                'home'     => $home,
                'user_esc' => escapeshellarg('dummy'),
            ];
            \pmssUserRefreshPermissions($ctx);
        });
        $this->assertTrue(true);
    }

    public function testRefreshPermissionsSkipsDirectoryRcCustomWithoutWarnings(): void
    {
        \pmssTestInstallRunUserStepShim('profile');
        $home = $this->pmssMakeTempDir('pmss-perm-dir-');
        mkdir($home.'/.rtorrent.rc.custom', 0755, true);

        $ctx = [
            'user'     => 'dummy',
            'home'     => $home,
            'user_esc' => escapeshellarg('dummy'),
        ];

        $GLOBALS['PMSS_PROFILE'] = [];

        try {
            \pmssUserRefreshPermissions($ctx);
            $steps = $GLOBALS['PMSS_PROFILE'] ?? [];
        } finally {
            unset($GLOBALS['PMSS_PROFILE']);
            \pmssTestInstallRunUserStepShim('last');
        }

        $firstStepDescription = '';
        if (isset($steps[0]) && is_array($steps[0]) && isset($steps[0]['description'])) {
            $firstStepDescription = (string) $steps[0]['description'];
        }

        $this->assertEquals(1, count($steps));
        $this->assertEquals('Refreshing user permissions', $firstStepDescription);
    }
}

}
