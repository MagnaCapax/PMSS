<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateStep2FinalPermissionsTest extends TestCase
{
    public function testFinalPermissionRefreshIsSoftFailWithTransitionMarkers(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/update-step2.php');

        $this->assertStringContainsAllStrings([
            "pmssLogJson(['event' => 'phase', 'name' => 'setupPermissions', 'status' => 'start']);",
            "\$setupPermissionsRc = runStep('Refreshing system permissions', '/scripts/util/setupPermissions.php');",
            "pmssLogJson(['event' => 'phase', 'name' => 'setupPermissions', 'status' => 'end', 'rc' => \$setupPermissionsRc]);",
            "PMSS_UPDATE_STEP_CLASS_SOFT_FAIL",
            "'setupPermissions_exit'",
            "pmssLogJson(['event' => 'phase', 'name' => 'transition', 'status' => 'leaving setupPermissions', 'rc' => \$setupPermissionsRc]);",
        ], $src);

        $this->assertOrderedStrings([
            "pmssLogJson(['event' => 'phase', 'name' => 'setupPermissions', 'status' => 'start']);",
            "\$setupPermissionsRc = runStep('Refreshing system permissions', '/scripts/util/setupPermissions.php');",
            "PMSS_UPDATE_STEP_CLASS_SOFT_FAIL",
            "'status' => 'leaving setupPermissions'",
            "runStep('Refreshing FTP configuration', '/scripts/util/ftpConfig.php');",
        ], $src);
    }
}
