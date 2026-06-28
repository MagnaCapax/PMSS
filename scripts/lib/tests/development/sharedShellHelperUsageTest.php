<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class SharedShellHelperUsageTest extends TestCase
{
    public function testShellLibraryDefinesSharedRunner(): void
    {
        $this->pmssAssertRepoFileContractCases([
            'scripts/lib/shell.php' => ['required' => ['function pmssRun(string $cmd, bool $logFailure = true): int', 'function pmssRunOrExit(string $cmd, bool $logFailure = true): void']],
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
        $this->pmssAssertRepoFileContractCases([
            'scripts/util/userPermissions.php' => [
                'required' => ["__DIR__.'/../lib/shell.php'", 'pmssRun('],
                'forbidden' => ['function run(string $cmd): int' => 'Expected userPermissions.php to stop defining a local run() helper'],
            ],
            'scripts/recreateUser.php' => [
                'required' => ["require_once __DIR__.'/lib/shell.php';", 'pmssRunOrExit('],
                'forbidden' => ['function run(string $cmd): void' => 'Expected recreateUser.php to stop defining a local run() helper'],
            ],
        ]);
    }
}
