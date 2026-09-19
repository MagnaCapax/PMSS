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

    public function testRefreshPermissionsPlansCurrentSkeletonMigration(): void
    {
        $skeleton = $this->pmssReadRepoFile('etc/skel/.rtorrent.rc.custom');
        $hookBlock = <<<'RC'

# Restore cached ruTorrent channel rates once its SCGI socket is ready.
schedule2 = throttle_init,5,0,"execute.nothrow=sh,-c,php ~/.rtorrentThrottleInit.php& exit 0"
RC;
        $legacy = str_replace($hookBlock, '', $skeleton, $replacements);

        $this->assertSame(1, $replacements, 'Expected one throttle startup hook in the skeleton');
        $this->assertSame('81d37b0b09345e3bfa5c2e79e66a3ef055f65905', sha1($legacy));
        $result = $this->runRefreshPermissionsWithStepRc(0, $legacy);

        $this->assertEquals(['Refreshing user permissions'], $result['descriptions']);
        $this->assertSame($skeleton, $result['content']);
        $this->assertSame(0640, $result['mode']);
    }

    public function testRefreshPermissionsPreservesCustomizedRtorrentConfig(): void
    {
        $result = $this->runRefreshPermissionsWithStepRc(0, "# customer override\n");

        $this->assertEquals(['Refreshing user permissions'], $result['descriptions']);
        $this->assertSame("# customer override\n", $result['content']);
    }

    public function testRefreshPermissionsSeedsAbsentConfigOnceWithCustomerMetadata(): void
    {
        $result = $this->runRefreshPermissionsWithStepRc(0, null, 'repeat');

        $this->assertSame($this->pmssReadRepoFile('etc/skel/.rtorrent.rc.custom'), $result['content']);
        $this->assertSame(0640, $result['mode']);
        $this->assertSame($result['expectedUid'], $result['uid']);
        $this->assertSame($result['firstInode'], $result['inode']);
    }

    public function testRefreshPermissionsPreservesEmptyAndCurrentConfigs(): void
    {
        foreach (['', $this->pmssReadRepoFile('etc/skel/.rtorrent.rc.custom')] as $content) {
            $result = $this->runRefreshPermissionsWithStepRc(0, $content);
            $this->assertSame($content, $result['content']);
            $this->assertSame(0600, $result['mode']);
        }
    }

    public function testRefreshPermissionsPreservesLiveAndDanglingSymlinks(): void
    {
        foreach (['link', 'dangling'] as $kind) {
            $result = $this->runRefreshPermissionsWithStepRc(0, null, $kind);
            $this->assertTrue($result['isLink']);
            $this->assertSame($kind === 'link' ? 'sentinel' : false, $result['linkTargetContent']);
        }
    }

    public function testRefreshPermissionsDryRunAndMissingSkeletonLeaveAbsentFileUntouched(): void
    {
        foreach (['dry', 'missing-skeleton'] as $kind) {
            $result = $this->runRefreshPermissionsWithStepRc(0, null, $kind);
            $this->assertSame(false, $result['content']);
            $this->assertSame('', $result['exception']);
        }
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
    private function runRefreshPermissionsWithStepRc(int $rc, ?string $rcCustomContent = null, string $kind = ''): array
    {
        $repoRoot = $this->pmssRepoRoot();
        $script = <<<'PHP'
$repoRoot = __REPO_ROOT__;
$home = sys_get_temp_dir().'/pmss-perm-rc-'.bin2hex(random_bytes(4));
@mkdir($home, 0755, true);
$path = $home.'/.rtorrent.rc.custom';
$kind = __KIND__;
putenv('PMSS_SKEL_DIR='.($kind === 'missing-skeleton' ? $home : $repoRoot.'/etc/skel'));
putenv('PMSS_DRY_RUN='.($kind === 'dry' ? '1' : '0'));
$account = posix_getpwuid(posix_geteuid());
$rcCustomContent = __RC_CUSTOM_CONTENT__;
if ($rcCustomContent !== null) {
    file_put_contents($path, $rcCustomContent);
    chmod($path, 0600);
}
if ($kind === 'link' || $kind === 'dangling') {
    if ($kind === 'link') file_put_contents($home.'/target', 'sentinel');
    symlink($home.'/target', $path);
}
$GLOBALS['PMSS_STEPS'] = [];
function runUserStep(string $user, string $description, string $command): int
{
    $GLOBALS['PMSS_STEPS'][] = ['description' => $description, 'command' => $command];
    return __STEP_RC__;
}
require $repoRoot.'/scripts/lib/update/users.php';
$exception = '';
try {
    $ctx = ['user' => $account['name'], 'home' => $home, 'user_esc' => escapeshellarg($account['name'])];
    pmssUserRefreshPermissions($ctx);
    clearstatcache();
    $firstInode = @fileinode($path);
    if ($kind === 'repeat') pmssUserRefreshPermissions($ctx);
} catch (Throwable $throwable) {
    $exception = get_class($throwable).': '.$throwable->getMessage();
}
$descriptions = array_map(static function (array $step): string {
    return (string) $step['description'];
}, $GLOBALS['PMSS_STEPS']);
clearstatcache();
echo json_encode([
    'exception' => $exception, 'descriptions' => $descriptions,
    'content' => @file_get_contents($path), 'mode' => @fileperms($path) & 0777,
    'uid' => @fileowner($path), 'expectedUid' => posix_geteuid(),
    'inode' => @fileinode($path), 'firstInode' => $firstInode ?? false,
    'isLink' => is_link($path), 'linkTargetContent' => @file_get_contents($home.'/target'),
]);
@unlink($path);
@unlink($home.'/target');
@rmdir($home);
PHP;

        return $this->pmssRunInlinePhpJson(
            str_replace(
                ['__REPO_ROOT__', '__STEP_RC__', '__RC_CUSTOM_CONTENT__', '__KIND__'],
                [var_export($repoRoot, true), (string) $rc, var_export($rcCustomContent, true), var_export($kind, true)],
                $script
            ),
            $this->pmssTestModeEnv()
        );
    }
}

}
