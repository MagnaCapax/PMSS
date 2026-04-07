<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class FuseOverlayfsSupportTest extends TestCase
{
    public function testDistUpgradeEnsuresFuseOverlayfsWhenAvailable(): void
    {
        $src = $this->pmssReadUpdateFile('distUpgrade.php');
        $this->assertTrue(strpos($src, 'pmssEnsureFuseOverlayfsAfterDistUpgrade') !== false, 'Expected post-upgrade fuse-overlayfs helper');
        $this->assertTrue(strpos($src, 'apt-cache show fuse-overlayfs') !== false, 'Expected availability check for fuse-overlayfs (architecture-aware)');
        $this->assertTrue(strpos($src, 'apt-get install') !== false && strpos($src, 'fuse-overlayfs') !== false, 'Expected apt-get install fuse-overlayfs step');
    }

    public function testPackageBootstrapQueuesFuseOverlayfs(): void
    {
        $src = $this->pmssReadUpdateAppFile('packages.php');
        $this->assertTrue(strpos($src, "'fuse-overlayfs'") !== false, 'Expected fuse-overlayfs to be part of package queue');
        $this->assertTrue(
            strpos($src, "if (\$version < 12) { \$dockerPackages[] = 'fuse-overlayfs'; }") === false,
            'fuse-overlayfs must not be gated only to Debian < 12'
        );
    }

    public function testUserMaintenanceEnforcesFuseOverlayfsBeyondDebian11(): void
    {
        $src = $this->pmssReadUpdateFile('userMaintenance.php');
        $this->assertTrue(strpos($src, 'fuse-overlayfs') !== false, 'Expected userMaintenance fuse-overlayfs logic');
        $this->assertTrue(strpos($src, 'if ($distroVersion >= 12)') === false, 'Expected fuse-overlayfs enforcement to apply on Debian 12+ when available');
    }
}
