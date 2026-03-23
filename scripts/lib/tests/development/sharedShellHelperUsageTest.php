<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class SharedShellHelperUsageTest extends TestCase
{
    public function testShellLibraryDefinesSharedRunner(): void
    {
        $source = $this->pmssReadRepoFile('scripts/lib/shell.php');

        $this->assertStringContainsString('function pmssRun(string $cmd, bool $logFailure = true): int', $source);
        $this->assertStringContainsString('function pmssRunOrExit(string $cmd, bool $logFailure = true): void', $source);
    }

    public function testUserPermissionsRequiresSharedShellLibrary(): void
    {
        $source = $this->pmssReadRepoFile('scripts/util/userPermissions.php');

        $this->assertStringContainsString("__DIR__.'/../lib/shell.php'", $source);
        $this->assertStringContainsString('pmssRun(', $source);
    }

    public function testUserPermissionsNoLongerDefinesLegacyRunHelper(): void
    {
        $source = $this->pmssReadRepoFile('scripts/util/userPermissions.php');

        $this->assertTrue(
            strpos($source, 'function run(string $cmd): int') === false,
            'Expected userPermissions.php to stop defining a local run() helper'
        );
    }

    public function testRecreateUserRequiresSharedShellLibrary(): void
    {
        $source = $this->pmssReadRepoFile('scripts/recreateUser.php');

        $this->assertStringContainsString("require_once __DIR__.'/lib/shell.php';", $source);
        $this->assertStringContainsString('pmssRunOrExit(', $source);
    }

    public function testRecreateUserNoLongerDefinesLegacyRunHelper(): void
    {
        $source = $this->pmssReadRepoFile('scripts/recreateUser.php');

        $this->assertTrue(
            strpos($source, 'function run(string $cmd): void') === false,
            'Expected recreateUser.php to stop defining a local run() helper'
        );
    }
}
