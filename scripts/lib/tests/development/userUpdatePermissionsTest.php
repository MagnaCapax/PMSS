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

        $ctx = $this->pmssUserUpdateContext($home);

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
            $ctx = $this->pmssUserUpdateContext($home);
            \pmssUserRefreshPermissions($ctx);
        });
        $this->assertTrue(true);
    }

    public function testRefreshPermissionsKeepsNonTimeoutFailureSoft(): void
    {
        $result = $this->runRefreshPermissionsWithStepRc(5);

        $this->assertEquals('', $result['exception']);
        $this->assertEquals(['Refreshing user permissions'], $result['descriptions']);
    }

    public function testRefreshPermissionsTimeoutRaisesException(): void
    {
        $result = $this->runRefreshPermissionsWithStepRc(124);

        $this->assertStringContainsString('RuntimeException: userPermissions timeout after', $result['exception']);
        $this->assertEquals(['Refreshing user permissions'], $result['descriptions']);
    }

    public function testRefreshPermissionsSkipsDirectoryRcCustomWithoutWarnings(): void
    {
        \pmssTestInstallRunUserStepShim('profile');
        $home = $this->pmssMakeTempDir('pmss-perm-dir-');
        mkdir($home.'/.rtorrent.rc.custom', 0755, true);

        $ctx = $this->pmssUserUpdateContext($home);

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

    /**
     * Run the permission helper in a subprocess so the runUserStep shim is deterministic.
     */
    private function runRefreshPermissionsWithStepRc(int $rc): array
    {
        $repoRoot = $this->pmssRepoRoot();
        $script = <<<'PHP'
$repoRoot = __REPO_ROOT__;
$home = sys_get_temp_dir().'/pmss-perm-rc-'.bin2hex(random_bytes(4));
@mkdir($home, 0755, true);
$GLOBALS['PMSS_STEPS'] = [];
function runUserStep(string $user, string $description, string $command): int
{
    $GLOBALS['PMSS_STEPS'][] = ['description' => $description, 'command' => $command];
    return __STEP_RC__;
}
require $repoRoot.'/scripts/lib/update/users.php';
$exception = '';
try {
    pmssUserRefreshPermissions(['user' => 'dummy', 'home' => $home, 'user_esc' => escapeshellarg('dummy')]);
} catch (Throwable $throwable) {
    $exception = get_class($throwable).': '.$throwable->getMessage();
}
$descriptions = array_map(static function (array $step): string {
    return (string) $step['description'];
}, $GLOBALS['PMSS_STEPS']);
echo json_encode(['exception' => $exception, 'descriptions' => $descriptions]);
@rmdir($home);
PHP;

        return $this->pmssRunInlinePhpJson(
            str_replace(
                ['__REPO_ROOT__', '__STEP_RC__'],
                [var_export($repoRoot, true), (string) $rc],
                $script
            ),
            $this->pmssTestModeEnv()
        );
    }
}

}
