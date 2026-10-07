<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';
require_once dirname(__DIR__, 2).'/user/add/provisioningRuntime.php';
require_once dirname(__DIR__, 2).'/user/add/preflight.php';
require_once dirname(__DIR__, 2).'/user/identity.php';

class AddUserProvisioningGuardTest extends TestCase
{
    public function testAddUserSourceContractsStayWired(): void
    {
        $this->assertTrue(function_exists('\pmssAddUserRuntimeInit'));

        $this->pmssAssertRepoFileContractCases([
            'scripts/addUser.php' => [
                'required' => [
                    'pmss-addUser-',
                    'pmssLockFileAcquire(',
                    "require_once 'lib/update.php';",
                    "require_once 'lib/update/users.php';",
                    "require_once 'lib/user/trafficLimit.php';",
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
