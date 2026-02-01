<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class FuseOverlayfsSupportTest extends TestCase
{
    public function testDistUpgradeEnsuresFuseOverlayfsWhenAvailable(): void
    {
        $path = dirname(__DIR__, 2).'/update/distUpgrade.php';
        $src = (string) @file_get_contents($path);
        $this->assertTrue($src !== '', 'Expected to read distUpgrade.php');
        $this->assertTrue(strpos($src, 'pmssEnsureFuseOverlayfsAfterDistUpgrade') !== false, 'Expected post-upgrade fuse-overlayfs helper');
        $this->assertTrue(strpos($src, 'apt-cache show fuse-overlayfs') !== false, 'Expected availability check for fuse-overlayfs (architecture-aware)');
        $this->assertTrue(strpos($src, 'apt-get install') !== false && strpos($src, 'fuse-overlayfs') !== false, 'Expected apt-get install fuse-overlayfs step');
    }

    public function testPackageBootstrapQueuesFuseOverlayfs(): void
    {
        $path = dirname(__DIR__, 2).'/update/apps/packages.php';
        $src = (string) @file_get_contents($path);
        $this->assertTrue($src !== '', 'Expected to read apps/packages.php');
        $this->assertTrue(strpos($src, "'fuse-overlayfs'") !== false, 'Expected fuse-overlayfs to be part of package queue');
        $this->assertTrue(
            strpos($src, "if (\$version < 12) { \$dockerPackages[] = 'fuse-overlayfs'; }") === false,
            'fuse-overlayfs must not be gated only to Debian < 12'
        );
    }

    public function testUserMaintenanceEnforcesFuseOverlayfsBeyondDebian11(): void
    {
        $path = dirname(__DIR__, 2).'/update/userMaintenance.php';
        $src = (string) @file_get_contents($path);
        $this->assertTrue($src !== '', 'Expected to read userMaintenance.php');
        $this->assertTrue(strpos($src, 'fuse-overlayfs') !== false, 'Expected userMaintenance fuse-overlayfs logic');
        $this->assertTrue(strpos($src, 'if ($distroVersion >= 12)') === false, 'Expected fuse-overlayfs enforcement to apply on Debian 12+ when available');
    }
}
