<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateBootstrapStep2FailureRecoveryTest extends TestCase
{
    public function testPhase2FailureRefreshesPermissionsBeforeFatal(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../update.php');

        $helperPos = strpos($src, 'function restoreSkelPermissionsBestEffort(string $context): void');
        $recoveryPos = strpos($src, "restoreSkelPermissionsBestEffort('update-step2 failure');");
        $fatalPos = strpos($src, "fatal('update-step2.php exited with status '.\$rc, \$rc);");

        $this->assertTrue($helperPos !== false, 'update.php should provide a shared post-stage permission recovery helper');
        $this->assertTrue($recoveryPos !== false, 'update.php should refresh skeleton/config permissions when update-step2 exits non-zero');
        $this->assertTrue($fatalPos !== false, 'update.php should still fatal when update-step2 exits non-zero');
        $this->assertTrue($recoveryPos < $fatalPos, 'Permission recovery must run before update.php surfaces the update-step2 failure');
        $this->assertStringContainsString('/scripts/util/setupSkelPermissions.php', $src, 'Permission recovery should keep using setupSkelPermissions.php');
    }
}
