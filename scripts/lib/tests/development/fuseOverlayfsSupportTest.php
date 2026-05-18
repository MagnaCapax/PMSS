<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class FuseOverlayfsSupportTest extends TestCase
{
    public function testDistUpgradeEnsuresFuseOverlayfsWhenAvailable(): void
    {
        $this->pmssAssertRepoFileContainsAllStrings('scripts/lib/update/distUpgrade.php', [
            'pmssEnsureFuseOverlayfsAfterDistUpgrade',
            'apt-cache show fuse-overlayfs',
            'apt-get install',
            'fuse-overlayfs',
        ]);
    }

    public function testUserMaintenanceEnforcesFuseOverlayfsBeyondDebian11(): void
    {
        $this->pmssAssertRepoFileContainsString('scripts/lib/update/userMaintenance.php', 'fuse-overlayfs', 'Expected userMaintenance fuse-overlayfs logic');
        $this->pmssAssertRepoFileNotContainsString('scripts/lib/update/userMaintenance.php', 'if ($distroVersion >= 12)', 'Expected fuse-overlayfs enforcement to apply on Debian 12+ when available');
    }
}
