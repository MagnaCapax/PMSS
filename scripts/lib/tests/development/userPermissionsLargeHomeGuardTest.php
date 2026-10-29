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
-not '.pmssOwnerIdSetFindPredicate('-uid', (int) $userIds['uid'], $uidRanges)
PHP;
        $gidFilter = <<<'PHP'
-not '.pmssOwnerIdSetFindPredicate('-gid', (int) $userIds['gid'], $gidRanges)
PHP;
        $ownerSpec = <<<'PHP'
escapeshellarg($userIds['uid'].':'.$userIds['gid'])
PHP;

        $this->pmssAssertRepoFileContract('scripts/util/userPermissions.php', [
            'required' => [
                '["/home/{$thisUser}/data", 0750],',
                '"/home/{$thisUser}/.resourceData",',
                '"/home/{$thisUser}/.throttle",',
                '["/home/{$thisUser}/.notifyEmail", 0640],',
                '["/home/{$thisUser}/.notifyEmail", "root:{$thisUser}"],',
                $dataPrune,
                $localPrune,
                '["/home/{$thisUser}/data", "{$thisUser}:{$thisUser}"],',
                $uidFilter,
                $gidFilter,
                $ownerSpec,
                "require_once __DIR__.'/../lib/user/subordinateIds.php';",
                "\$findParts[] = '-execdir chown -h';",
                'find %s \( -path %s -o -path %s -o -type d -not -uid %d \) -prune -o -type d -not -perm 0750 -exec chmod 0750 {} +',
                'escapeshellarg("/home/{$thisUser}/.config"),',
                '["/home/{$thisUser}/.config", 0770, true, true],',
                'find %s -type d -not -uid %d -prune -o -type f -links 1 -uid %d -perm /0111 -exec chmod a-x {} +',
                'function pmssFindOwnerMismatchPredicate(string $owner): string',
                '\( -not -user %s -o -not -group %s \)',
                "pmssUserPermissionsOwnerApply(\$homeDir, \$path, \$owner, \$predicate, \$predicate !== '');",
            ],
            'forbidden' => [
                '["/home/{$thisUser}/data", 0750, true],' => 'Expected data tree chmod to avoid recursive mode',
                '["/home/{$thisUser}/.config", 0770, true],' => '~/.config is strip-only so owner-only modes survive (#1217)',
                "pmssRun(sprintf('chown %s%s %s'" => 'Direct ownership command must stay out of the account tree',
                "pmssRun(sprintf('chmod %s%o %s'" => 'Direct mode command must stay out of the account tree',
            ],
        ]);
    }

    public function testLegacyPermissionStepsSelectOnlyDirectRegularEntries(): void
    {
        $this->pmssAssertRepoFileContract('scripts/util/setupUserHomePermissions.php', [
            'required' => [
                "pmssUserPermissionsOwnerApply(\$homeDir, \$target, 'root:root');",
                "pmssUserPermissionsModeApply(\$userName, \$homeDir, \$accountUid, \$target, 0775);",
                "pmssUserPermissionsModeApply(\$userName, \$homeDir, \$accountUid, \$lighttpdDir, 0750);",
            ],
            'forbidden' => [
                '-exec chown' => 'Owners change through -execdir from a find anchored at the home',
                '-exec chmod' => 'Modes change as the account or through the opened file',
                "'chown root:root '" => 'Direct ownership must stay out of account paths',
            ],
        ]);
    }
}
