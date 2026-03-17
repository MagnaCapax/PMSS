<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserPermissionsLargeHomeGuardTest extends TestCase
{
    private function readSource(): string
    {
        $path = dirname(__DIR__, 4).'/scripts/util/userPermissions.php';
        $src = @file_get_contents($path);
        $this->assertTrue(is_string($src) && $src !== '', 'Expected to read '.$path);
        return $src;
    }

    public function testLargeDataTreeChmodIsNotRecursive(): void
    {
        $src = $this->readSource();

        $this->assertStringContainsString('["/home/{$thisUser}/data", 0750],', $src);
        $this->assertTrue(
            strpos($src, '["/home/{$thisUser}/data", 0750, true],') === false,
            'Expected data tree chmod to avoid recursive mode'
        );
    }

    public function testLargeDataTreeOwnershipWalkIsPruned(): void
    {
        $src = $this->readSource();
        $dataPrune = <<<'PHP'
escapeshellarg("/home/{$thisUser}/data").' -prune -o';
PHP;
        $localPrune = <<<'PHP'
escapeshellarg("/home/{$thisUser}/.local").' -prune -o';
PHP;

        $this->assertStringContainsString($dataPrune, $src);
        $this->assertStringContainsString($localPrune, $src);
        $this->assertStringContainsString('["/home/{$thisUser}/data", "{$thisUser}:{$thisUser}"],', $src);
    }

    public function testHomeTreeChownUsesOwnershipMismatchFilter(): void
    {
        $src = $this->readSource();
        $uidFilter = <<<'PHP'
-not -uid '.(string) $userIds['uid']
PHP;
        $gidFilter = <<<'PHP'
-not -gid '.(string) $userIds['gid']
PHP;
        $ownerSpec = <<<'PHP'
escapeshellarg($userIds['uid'].':'.$userIds['gid'])
PHP;

        $this->assertStringContainsString($uidFilter, $src);
        $this->assertStringContainsString($gidFilter, $src);
        $this->assertStringContainsString($ownerSpec, $src);
    }
}
