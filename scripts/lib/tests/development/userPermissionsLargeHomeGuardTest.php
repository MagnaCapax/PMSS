<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserPermissionsLargeHomeGuardTest extends TestCase
{
    public function testLargeHomePermissionWalksStayPrunedAndTargeted(): void
    {
        $dataPrune = <<<'PHP'
escapeshellarg("/home/{$thisUser}/data").' -prune -o';
PHP;
        $localPrune = <<<'PHP'
escapeshellarg("/home/{$thisUser}/.local").' -prune -o';
PHP;
        $uidFilter = <<<'PHP'
-not -uid '.(string) $userIds['uid']
PHP;
        $gidFilter = <<<'PHP'
-not -gid '.(string) $userIds['gid']
PHP;
        $ownerSpec = <<<'PHP'
escapeshellarg($userIds['uid'].':'.$userIds['gid'])
PHP;

        $this->pmssAssertRepoFileContract('scripts/util/userPermissions.php', [
            'required' => [
                '["/home/{$thisUser}/data", 0750],',
                '"/home/{$thisUser}/.resourceData",',
                '["/home/{$thisUser}/.notifyEmail", 0640],',
                '["/home/{$thisUser}/.notifyEmail", "root:{$thisUser}"],',
                $dataPrune,
                $localPrune,
                '["/home/{$thisUser}/data", "{$thisUser}:{$thisUser}"],',
                $uidFilter,
                $gidFilter,
                $ownerSpec,
                '$mode = sprintf(\'%04o\', $perm);',
                'find %s -not -type l -not -perm %s -exec chmod %s {} +',
                'find %s -path %s -prune -o -type d -not -perm 0750 -exec chmod 0750 {} +',
                'function pmssFindOwnerMismatchPredicate(string $owner): string',
                '\( -not -user %s -o -not -group %s \)',
                'find %s -not -type l %s -exec chown %s {} +',
            ],
            'forbidden' => [
                '["/home/{$thisUser}/data", 0750, true],' => 'Expected data tree chmod to avoid recursive mode',
            ],
        ]);
    }
}
