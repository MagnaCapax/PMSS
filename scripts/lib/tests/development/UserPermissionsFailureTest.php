<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UserPermissionsFailureTest extends TestCase
{
    public function testFailedRepairCommandsSetExitStatusAndReportFirstFailure(): void
    {
        foreach ([
            [[0], ['find first'], 0, ''],
            [[1], ['find first'], 1, "userPermissions: 1 command(s) failed; first: find first\n"],
            [[0, 1, 1], ['find first', 'find second', 'find third'], 1,
                "userPermissions: 2 command(s) failed; first: find second\n"],
        ] as $case) {
            [$results, $commands, $expectedRc, $expectedStderr] = $case;
            $code = 'function pmssRun(string $command): int { '
                .'$GLOBALS["seen"][] = $command; return array_shift($GLOBALS["results"]); } '
                .'$GLOBALS["results"] = '.var_export($results, true).'; '
                .'require '.var_export($this->pmssRepoPath('scripts/lib/user/permissionsCommands.php'), true).'; '
                .implode(' ', array_map(static function (string $command): string {
                    return 'pmssUserPermissionsRun('.var_export($command, true).');';
                }, $commands))
                .'exit(pmssUserPermissionsResult());';
            $run = $this->pmssExecShellCommandWithTempStderr(
                escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code)
            );

            $this->assertSame($expectedRc, $run['result']['rc']);
            $this->assertSame($expectedStderr, file_get_contents($run['stderrPath']));
        }
    }

    public function testRecreateUserKeepsPermissionRepairAdvisory(): void
    {
        $source = $this->pmssReadRepoFile('scripts/recreateUser.php');
        $this->assertStringContainsString("pmssRun('/scripts/util/userPermissions.php '", $source);
        $this->assertStringNotContainsString("pmssRunOrExit('/scripts/util/userPermissions.php '", $source);
    }
}
