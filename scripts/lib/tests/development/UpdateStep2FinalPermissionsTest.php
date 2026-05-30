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
            // Anchor on the setupPermissions-specific reason, not bare SOFT_FAIL:
            // GH#592 added an earlier SOFT_FAIL (user-maintenance mismatch) so
            // the first global SOFT_FAIL occurrence is no longer this block's.
            "'setupPermissions_exit'",
            "'status' => 'leaving setupPermissions'",
            "runStep('Refreshing FTP configuration', '/scripts/util/ftpConfig.php');",
        ], $src);
    }

    public function testPermissionHardeningFiltersModeMismatches(): void
    {
        $src = $this->pmssReadRepoFile('scripts/util/update-step2.php');

        $this->assertStringContainsAllStrings([
            'find /home -mindepth 1 -maxdepth 1 %s -prune -o -type %s -not -perm %s -exec chmod %s {} +',
            "runStep('Resetting /etc/seedbox permissions', 'find /etc/seedbox -not -type l -not -perm 0755 -exec chmod 0755 {} +');",
            "runStep('Resetting /scripts permissions', 'find /scripts -not -type l -not -perm 0750 -exec chmod 0750 {} +');",
        ], $src);

        $this->assertStringNotContainsString("runStep('Resetting /etc/seedbox permissions', 'chmod -R 755 /etc/seedbox');", $src);
        $this->assertStringNotContainsString("runStep('Resetting /scripts permissions', 'chmod -R 750 /scripts');", $src);
    }
}
