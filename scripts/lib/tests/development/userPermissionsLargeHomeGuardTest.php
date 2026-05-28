<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserPermissionsLargeHomeGuardTest extends TestCase
{
    public function testLargeDataTreeChmodIsNotRecursive(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/util/userPermissions.php', '["/home/{$thisUser}/data", 0750],');
        $this->pmssAssertRepoFileNotContainsString('scripts/util/userPermissions.php', '["/home/{$thisUser}/data", 0750, true],', 'Expected data tree chmod to avoid recursive mode');
    }

    public function testLargeDataTreeOwnershipWalkIsPruned(): void
    {
        $dataPrune = <<<'PHP'
escapeshellarg("/home/{$thisUser}/data").' -prune -o';
PHP;
        $localPrune = <<<'PHP'
escapeshellarg("/home/{$thisUser}/.local").' -prune -o';
PHP;

        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/util/userPermissions.php',
            [$dataPrune, $localPrune, '["/home/{$thisUser}/data", "{$thisUser}:{$thisUser}"],']
        );
    }

    public function testHomeTreeChownUsesOwnershipMismatchFilter(): void
    {
        $uidFilter = <<<'PHP'
-not -uid '.(string) $userIds['uid']
PHP;
        $gidFilter = <<<'PHP'
-not -gid '.(string) $userIds['gid']
PHP;
        $ownerSpec = <<<'PHP'
escapeshellarg($userIds['uid'].':'.$userIds['gid'])
PHP;

        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/userPermissions.php', [$uidFilter, $gidFilter, $ownerSpec]);
    }
}
