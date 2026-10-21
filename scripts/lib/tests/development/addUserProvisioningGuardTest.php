<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/add/provisioningRuntime.php';
require_once dirname(__DIR__, 2).'/user/add/preflight.php';
require_once dirname(__DIR__, 2).'/user/identity.php';
require_once dirname(__DIR__, 2).'/user/add/systemUserCreate.php';

class AddUserProvisioningGuardTest extends TestCase
{
    public function testHelpLoadsLibrariesFromScriptDirectory(): void
    {
        $cwd = $this->pmssMakeTempDir('pmss-adduser-cwd-', 0777);
        mkdir($cwd.'/lib/user/add', 0777, true);
        $marker = $cwd.'/loaded';
        file_put_contents($cwd.'/lib/user/add/cli.php', '<?php file_put_contents('.var_export($marker, true).', "yes");');
        $command = 'cd '.escapeshellarg($cwd).' && '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($this->pmssRepoPath('scripts/addUser.php')).' --help 2>&1';
        $output = [];
        $status = 1;
        exec($command, $output, $status);
        $this->assertSame(0, $status);
        $this->assertFalse(file_exists($marker));
        $this->assertTrue(strpos(implode("\n", $output), 'Usage') !== false);
    }

    public function testAddUserSourceContractsStayWired(): void
    {
        $this->assertTrue(function_exists('\pmssAddUserRuntimeInit'));

        $this->pmssAssertRepoFileContractCases([
            'scripts/addUser.php' => [
                'required' => [
                    'pmss-addUser-',
                    'pmssLockFileAcquire(',
                    "require_once __DIR__.'/lib/update.php';",
                    "require_once __DIR__.'/lib/update/users.php';",
                    "require_once __DIR__.'/lib/user/trafficLimit.php';",
                    "if (!pmssUpdateUserEnvironment(\$user['name'])) {",
                    "'web_root_convergence_failed'",
                    'pmssTrafficLimitCliTargetModes($user[\'name\'], $homePath)',
                    'pmssTrafficLimitPersistTargetModes($targetModes, (int) $user[\'trafficLimit\'], $persistError)',
                ],
                'forbidden' => [
                    '@file_put_contents($runtimeDir' => 'addUser.php must not reimplement runtime traffic limit writes',
                    '@file_put_contents("/home/{$user[\'name\']}/.trafficLimit"' => 'addUser.php must not reimplement home traffic limit writes',
                ],
                'ordered' => [
                    [
                        'needles' => ['/scripts/startRtorrent', '/scripts/startLighttpd', '/scripts/util/setupNetwork.php'],
                        'missingPrefix' => 'addUser.php missing service/network substring: ',
                        'orderPrefix' => 'addUser.php service/network order changed near: ',
                    ],
                    [
                        'needles' => ['pmssUpdateUserEnvironment(', '/scripts/startLighttpd'],
                        'missingPrefix' => 'addUser.php missing frontend refresh substring: ',
                        'orderPrefix' => 'addUser.php must converge user environment before services start: ',
                    ],
                ],
            ],
            'scripts/lib/user/add/provisioningRuntime.php' => [
                'required' => ['###ADDUSER:', '###ADDUSER_JSON:', 'function pmssAddUserRuntimeInit('],
            ],
        ]);
    }

    public function testAddUserWrapperStaysSmall(): void
    {
        $lines = file($this->pmssRepoPath('scripts/addUser.php'), FILE_IGNORE_NEW_LINES);
        $this->assertTrue(is_array($lines), 'addUser.php must be readable');
        $this->assertTrue(count($lines) <= 200, 'addUser.php must stay under 200 lines');
    }

    public function testCronSpoolPreflightRemovesOnlyUnclaimedRegularFile(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-adduser-cron-');
        $path = $dir.'/alice';
        $orphan = static function (int $uid) {
            return false;
        };
        $claimed = static function (int $uid): array {
            return array('uid' => $uid, 'name' => 'other');
        };

        $this->assertSame('absent', \pmssAddUserCronSpoolPreflight('alice', $dir, $orphan));
        $this->pmssWriteFile($path, 'old cron');
        $this->assertSame('claimed', \pmssAddUserCronSpoolPreflight('alice', $dir, $claimed));
        $this->assertSame('old cron', file_get_contents($path));
        $this->assertSame('removed', \pmssAddUserCronSpoolPreflight('alice', $dir, $orphan));
        $this->assertFalse(file_exists($path));
    }

    public function testCronSpoolPreflightRefusesUnsafeEntries(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-adduser-cron-');
        $path = $dir.'/alice';
        $orphan = static function (int $uid) {
            return false;
        };

        $this->assertSame('unsafe', \pmssAddUserCronSpoolPreflight('../alice', $dir, $orphan));
        $this->assertTrue(mkdir($path));
        $this->assertSame('unsafe', \pmssAddUserCronSpoolPreflight('alice', $dir, $orphan));
        $this->assertTrue(rmdir($path));
        $target = $this->pmssWriteFile($dir.'/target', 'keep');
        $this->assertTrue(symlink($target, $path));
        $this->assertSame('unsafe', \pmssAddUserCronSpoolPreflight('alice', $dir, $orphan));
        $this->assertSame('keep', file_get_contents($target));
    }

    public function testScreenSocketPreparePreservesAbsentAndCurrentOwnerTrees(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-adduser-screen-');
        $path = $dir.'/S-alice';
        $this->assertSame('absent', \pmssAddUserScreenSocketPrepare('alice', 1234, $dir));
        $this->screenDirectoryCreate($path);
        $this->pmssWriteFile($path.'/session', 'keep');
        $this->assertSame('ready', \pmssAddUserScreenSocketPrepare('alice', fileowner($path), $dir));
        $this->assertSame('keep', file_get_contents($path.'/session'));
        $this->assertSame([], glob($dir.'/.pmss-remove-*'));
    }

    public function testScreenSocketPrepareRemovesStaleTreeWithoutFollowingInnerLinks(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-adduser-screen-');
        $path = $dir.'/S-alice';
        $target = $this->pmssWriteFile($dir.'/outside', 'keep');
        $this->screenDirectoryCreate($path);
        $this->assertTrue(symlink($target, $path.'/socket'));
        $this->assertSame('removed', \pmssAddUserScreenSocketPrepare('alice', fileowner($path) + 1, $dir));
        $this->assertFalse(file_exists($path));
        $this->assertSame('keep', file_get_contents($target));
        $this->assertSame([], glob($dir.'/.pmss-remove-*'));
        // Screen can create a fresh directory on its next account-owned launch.
        $this->screenDirectoryCreate($path);
        $this->assertSame('ready', \pmssAddUserScreenSocketPrepare('alice', fileowner($path), $dir));
    }

    public function testScreenSocketRemovalFailureLeavesWholeTreeInPrivateStage(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-adduser-screen-');
        $path = $dir.'/S-alice';
        $this->screenDirectoryCreate($path);
        $this->pmssWriteFile($path.'/session', 'stale');
        $uid = fileowner($path);
        $inode = fileinode($path);
        $args = $dir.'/rm-args';
        $bin = $this->pmssMakeExecutableStub('rm', "#!/bin/sh\nprintf '%s\\n' \"\$@\" >\"\$PMSS_TEST_SCREEN_RM_ARGS\"\nexit 1\n", 'pmss-screen-rm-');
        $this->pmssWithPathPrefixedEnv($bin, ['PMSS_TEST_SCREEN_RM_ARGS' => $args], function () use ($dir, $uid): void {
            $this->assertSame('remove_failed', \pmssAddUserScreenSocketPrepare('alice', $uid + 1, $dir));
        });
        $stages = glob($dir.'/.pmss-remove-*');
        $this->assertSame(1, count($stages));
        $this->assertFalse(file_exists($path));
        $this->assertSame(0700, fileperms($stages[0]) & 07777);
        $this->assertSame(posix_geteuid(), fileowner($stages[0]));
        $this->assertSame($inode, fileinode($stages[0].'/tree'));
        $this->assertSame('stale', file_get_contents($stages[0].'/tree/session'));
        $this->assertSame(['-rf', '--one-file-system', '--', $stages[0]], file($args, FILE_IGNORE_NEW_LINES));
    }

    public function testScreenSocketPrepareRejectsInvalidScopeAndUnusualEntries(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-adduser-screen-');
        foreach (['', '../alice', "alice\0suffix", 'root'] as $name) {
            $this->assertSame('unsafe', \pmssAddUserScreenSocketPrepare($name, 1234, $dir));
        }
        foreach ([0, -1] as $uid) {
            $this->assertSame('unsafe', \pmssAddUserScreenSocketPrepare('alice', $uid, $dir));
        }
        $this->assertSame('unsafe', \pmssAddUserScreenSocketPrepare('alice', 1234, $dir."\0suffix"));
        $path = $this->pmssWriteFile($dir.'/S-alice', 'keep');
        $this->assertSame('unsafe', \pmssAddUserScreenSocketPrepare('alice', fileowner($path) + 1, $dir));
        $this->assertSame('keep', file_get_contents($path));
    }

    public function testScreenSocketPrepareRejectsSymlinkedLeafAndParent(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-adduser-screen-');
        $this->assertTrue(mkdir($dir.'/target'));
        $this->pmssWriteFile($dir.'/target/keep', 'keep');
        $this->assertTrue(symlink($dir.'/target', $dir.'/S-alice'));
        $this->assertSame('unsafe', \pmssAddUserScreenSocketPrepare('alice', 1234, $dir));
        $this->assertTrue(symlink($dir, $dir.'/redirect'));
        $this->assertSame('unsafe', \pmssAddUserScreenSocketPrepare('alice', 1234, $dir.'/redirect'));
        $this->assertTrue(is_link($dir.'/S-alice'));
        $this->assertSame('keep', file_get_contents($dir.'/target/keep'));
    }

    public function testScreenSocketPrepareReportsFailedOrIncompleteRemoval(): void
    {
        $dir = $this->pmssMakeTempDir('pmss-adduser-screen-');
        $path = $dir.'/S-alice';
        $this->screenDirectoryCreate($path);
        foreach ([false, true] as $result) {
            $this->assertSame('remove_failed', \pmssAddUserScreenSocketPrepare('alice', fileowner($path) + 1, $dir,
                function (string $target) use ($path, $result): bool {
                    $this->assertSame($path, $target);
                    return $result;
                }
            ));
            $this->assertTrue(is_dir($path));
        }
    }

    public function testScreenSocketGuardRunsBeforeCredentialsAndServiceStarts(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/lib/user/add/systemUserCreate.php', [
            'pmssAddUserFailureRollbackMarkSystemUserCreated();',
            "pmssAddUserScreenSocketPrepare(\$user['name'],",
            "'screen_socket_'.\$screenState",
            "'Set initial password'",
        ]);
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/addUser.php', [
            'pmssAddUserSystemUserCreate($user, $homePath);',
            '/scripts/startRtorrent',
        ]);
    }

    /** Model an ordinary account UID even when the suite runs as root. */
    private function screenDirectoryCreate(string $path): void
    {
        $this->assertTrue(mkdir($path, 0700));
        if (fileowner($path) === 0) {
            $this->assertTrue(chown($path, 1234));
            clearstatcache(true, $path);
        }
    }

    public function testAddUserRejectsReservedRuntimeAndDaemonNames(): void
    {
        $reservedNames = [
            'lxc', 'runc', 'crun', 'podman', 'qemu', 'dockerd', 'virsh', 'xen', 'vagrant',
            'cron', 'crond', 'anacron', 'systemd', 'journal', 'journald', 'init', 'udev', 'udevd', 'logind',
            'named', 'nscd', 'sssd', 'nslcd', 'samba', 'smbd', 'nmbd', 'monit', 'snmpd', 'rpcbind', 'fail2ban',
            // pmseedbox signup-layer baseline reconcile (2026-08-25). 'su' is also reserved-listed
            // but the min-length (>=3) check precedes the reserved check, so it returns too_short.
            'mrfarmer', 'wheel', 'slice', 'cgroup', 'user', 'exim',
        ];

        foreach ($reservedNames as $name) {
            $this->assertTrue(\pmssValidateUsername($name), 'Legacy validation must still accept '.$name);
            $error = \pmssUsernameCreateValidationError($name);
            $this->assertTrue(is_array($error), 'Expected create validation error for '.$name);
            $this->assertEquals('reserved', $error['code'], 'Unexpected create validation code for '.$name);
        }

        $this->assertEquals(null, \pmssUsernameCreateValidationError('abc123'));
    }
}
