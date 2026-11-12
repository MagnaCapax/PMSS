<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class SharedShellHelperUsageTest extends TestCase
{
    public function testShellLibraryDefinesSharedRunner(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/shell.php', [
            'function pmssRun(string $cmd, bool $logFailure = true): int', 'function pmssRunOrExit(string $cmd, bool $logFailure = true): void',
        ]);
    }

    public function testShellRunnerRejectsEmptyCommandBeforePassthru(): void
    {
        $shellLib = var_export($this->pmssRepoPath('scripts/lib/shell.php'), true);
        $output = $this->pmssRunInlinePhp(
            'require_once '.$shellLib.'; echo pmssRun("   ", true);',
            [],
            '2>&1'
        );

        $this->assertEquals("Command failed (rc=1): empty command\n1", trim($output));
    }

    public function testUserPermissionsUsesSharedShellLibrary(): void
    {
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/util/userPermissions.php',
            ["__DIR__.'/../lib/shell.php'", "__DIR__.'/../lib/user/permissionsCommands.php'", 'pmssUserPermissionsRun('],
            ['function run(string $cmd): int' => 'Expected userPermissions.php to stop defining a local run() helper']);
        // permissionsApply.php reaches pmssRun() and does not load shell.php itself,
        // so every entry point that applies permissions loads it.
        $this->pmssAssertRepoFileContainsAllStrings('scripts/util/setupUserHomePermissions.php',
            ["__DIR__.'/../lib/shell.php'", "__DIR__.'/../lib/user/permissionsApply.php'"]);
        $this->pmssAssertRepoFileContainsAndOmitsStrings('scripts/recreateUser.php',
            ["require_once __DIR__.'/lib/shell.php';", 'pmssRunOrExit('],
            ['function run(string $cmd): void' => 'Expected recreateUser.php to stop defining a local run() helper']);
    }
}
