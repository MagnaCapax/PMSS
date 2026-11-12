<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

/**
 * Concurrent userPermissions passes on one account raced on the traffic files'
 * immutable flag (EPERM on chown); the per-account lock must be held before any change.
 */
class UserPermissionsLockTest extends TestCase
{
    public function testPerAccountLockPrecedesEveryMutation(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings('scripts/util/userPermissions.php', [
            'if (!is_array($userIds)) die("No such user\n");',
            "\$permissionsLock = pmssLockFileAcquire(pmssRuntimeLockPath('pmss-userPermissions-'.\$thisUser.'.lock'), false, 'ce');",
            'if ($permissionsLock === false) {',
            'pmssUserPermissionsOwnerApply($homeDir, $homeDir,',
            'pmssTrafficSetImmutable($trafficFile, false);',
            'pmssTrafficSetImmutable($trafficFile, true);',
            'exit(pmssUserPermissionsResult());',
        ]);
    }

    public function testLockIsBlockingCloseOnExecAndNeverReleasedEarly(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/util/userPermissions.php', [
            "'pmss-userPermissions-'.\$thisUser.'.lock'), false, 'ce');",
        ], [
            'pmssLockHandleRelease($permissionsLock' => 'The lock must be held until the final immutable set has run',
            'fclose($permissionsLock' => 'The lock must be held until the final immutable set has run',
        ]);
        $this->pmssAssertRepoFileSubstringCount('scripts/util/userPermissions.php', 'pmssLockFileAcquire(', 1);
    }
}
