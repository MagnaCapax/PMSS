<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class UpdateStep2DpkgRecoveryOrderTest extends TestCase
{
    public function testPendingDpkgRecoveryRunsBeforeHomeInodeDensityCheck(): void
    {
        $this->pmssAssertRepoFileContainsOrderedStrings(
            'scripts/util/update-step2.php',
            [
                "pmssRunProfiledCallable('Running update-step2 preflight checks'",
                "pmssRunProfiledCallable('Completing pending dpkg configurations', 'pmssCompletePendingDpkg');",
                "pmssRunProfiledCallable('Checking /home inode density', 'pmssHomeInodeDensityCheck', ['logmsg'], PMSS_UPDATE_STEP_CLASS_SOFT_FAIL);",
            ],
            'Missing update-step2 recovery ordering marker: ',
            'Pending dpkg recovery must run before: '
        );
    }

    public function testPendingDpkgRecoveryUsesConffileDefaults(): void
    {
        $this->pmssAssertRepoFileContainsString(
            'scripts/lib/update/environment.php',
            "dpkgCmd('--force-confdef --force-confold --configure -a')",
            'dpkg --configure -a must keep PMSS conffiles on unattended recovery'
        );
    }
}
