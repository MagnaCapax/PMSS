<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateBootstrapStep2FailureRecoveryTest extends TestCase
{
    public function testPhase2FailureRefreshesPermissionsBeforeFatal(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings(
            'scripts/update.php',
            [
                'function restorePermissionsBestEffort(string $context): void',
                "restorePermissionsBestEffort('update-step2 failure');",
                '/scripts/util/setupPermissions.php',
            ],
            'update.php should keep permission recovery: '
        );
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/update.php',
            ["restorePermissionsBestEffort('update-step2 failure');", "fatal('update-step2.php exited with status '.\$rc, \$rc);"],
            'update.php should still fatal when update-step2 exits non-zero: ',
            'Permission recovery must run before update.php surfaces the update-step2 failure: '
        );
    }
}
