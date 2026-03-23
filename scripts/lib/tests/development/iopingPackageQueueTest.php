<?php
namespace PMSS\Tests;

require_once __DIR__.'/../common/TestCase.php';

class IopingPackageQueueTest extends TestCase
{
    public function testSystemPackagesQueueIncludesIoping(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/apps/packages.php');
        $this->assertTrue(strpos($src, "'ioping'") !== false, 'Expected ioping to be queued as a standard package');
    }

    public function testPackageBootstrapIncludesZncPackages(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/apps/packages.php');
        $this->assertTrue(strpos($src, "'znc'") !== false, 'Expected ZNC to stay in the package bootstrap queue');
        $this->assertTrue(strpos($src, "'znc-python3'") !== false, 'Expected ZNC Python support to stay queued');
    }

    public function testPackageBootstrapKeepsDebian10BackportsKernelQueue(): void
    {
        $src = $this->pmssReadRepoFile('scripts/lib/update/apps/packages.php');
        $this->assertTrue(strpos($src, "'linux-image-amd64'") !== false, 'Expected Debian 10 kernel queue to remain present');
        $this->assertTrue(strpos($src, "'buster-backports'") !== false, 'Expected Debian 10 backports target to remain present');
    }
}
